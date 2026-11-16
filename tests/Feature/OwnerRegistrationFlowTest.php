<?php

namespace Tests\Feature;

use App\Enums\BusinessStatus;
use App\Models\{Branch, Business, BusinessReviewEvent, MediaFile, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Mail, Storage};
use Tests\TestCase;

class OwnerRegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $legacyEnvironment;

    protected function setUp(): void
    {
        $this->legacyEnvironment = [
            $_ENV['BEAUTYBOOK_LEGACY_UI'] ?? null,
            $_SERVER['BEAUTYBOOK_LEGACY_UI'] ?? null,
            getenv('BEAUTYBOOK_LEGACY_UI'),
        ];
        $_ENV['BEAUTYBOOK_LEGACY_UI'] = $_SERVER['BEAUTYBOOK_LEGACY_UI'] = 'false';
        putenv('BEAUTYBOOK_LEGACY_UI=false');
        parent::setUp();
        $this->seed();
        config(['media.private_disk' => 'local']);
        Storage::fake('local');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            [$environment, $server, $process] = $this->legacyEnvironment;
            if ($environment === null) unset($_ENV['BEAUTYBOOK_LEGACY_UI']);
            else $_ENV['BEAUTYBOOK_LEGACY_UI'] = $environment;
            if ($server === null) unset($_SERVER['BEAUTYBOOK_LEGACY_UI']);
            else $_SERVER['BEAUTYBOOK_LEGACY_UI'] = $server;
            putenv($process === false ? 'BEAUTYBOOK_LEGACY_UI' : 'BEAUTYBOOK_LEGACY_UI='.$process);
        }
    }

    public function test_fresh_owner_keeps_one_registration_business_through_approval_and_first_branch(): void
    {
        $businessCount = Business::count();
        $registration = $this->postJson('/api/v1/auth/register', [
            'accountType' => 'BUSINESS_OWNER',
            'fullName' => 'Registration Flow Owner',
            'email' => 'registration-flow@example.com',
            'phone' => '0909876543',
            'password' => 'BeautyBook123!',
        ])->assertCreated()
            ->assertJsonPath('user.roles', ['BUSINESS_OWNER'])
            ->assertJsonPath('user.sessionType', 'salon')
            ->assertJsonPath('user.workspace', 'SALON')
            ->assertJsonPath('user.branchId', null);

        $owner = User::where('email', 'registration-flow@example.com')->firstOrFail();
        $assignment = $owner->roleAssignments()->sole();
        $business = Business::findOrFail($assignment->business_id);
        $businessId = (string) $business->id;
        $registration->assertJsonPath('user.id', (string) $owner->id)
            ->assertJsonPath('user.businessId', $businessId)
            ->assertJsonPath('user.scopes', [[
                'code' => 'BUSINESS_OWNER', 'businessId' => $businessId,
                'branchId' => null, 'expiresAt' => null,
            ]]);
        $this->assertSame('BUSINESS_OWNER', $assignment->role_name);
        $this->assertNull($assignment->branch_id);
        $this->assertSame($owner->id, $business->ownerProfile->user_id);
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertSame($businessCount + 1, Business::count());
        $this->assertSame(1, Business::where('owner_profile_id', $business->owner_profile_id)->count());
        $this->assertDatabaseMissing('branches', ['business_id' => $business->id]);
        $this->withHeader('Authorization', 'Bearer '.$registration->json('accessToken'));
        $this->getJson('/api/v1/business/onboarding/mine')->assertOk()
            ->assertJsonPath('id', $businessId)->assertJsonPath('status', 'DRAFT');

        $payload = [
            'name' => 'Registration Flow Studio', 'slug' => 'registration-flow-studio',
            'contactEmail' => $owner->email, 'contactPhone' => '0909876543',
            'addressLine' => '100 Registration Road',
            'companyName' => 'Registration Flow Company', 'taxCode' => '0399876543',
            'legalEntityType' => 'ENTERPRISE', 'legalName' => 'Registration Flow Company',
            'registrationNumber' => 'REG-FLOW-001', 'registeredAddress' => '100 Registration Road',
            'onboardingStep' => 3,
        ];
        $draftUrl = '/api/v1/business/'.$businessId.'/onboarding';
        $this->patchJson($draftUrl, array_replace($payload, ['taxCode' => '0312345678']))
            ->assertUnprocessable()->assertJsonValidationErrors(['taxCode']);
        $this->assertSame($businessCount + 1, Business::count());
        $this->assertSame(1, Business::where('owner_profile_id', $business->owner_profile_id)->count());
        foreach ([1, 2] as $save) {
            $this->patchJson($draftUrl, $payload)->assertOk()
                ->assertJsonPath('id', $businessId)
                ->assertJsonPath('legalEntityType', 'ENTERPRISE')
                ->assertJsonPath('registrationNumber', 'REG-FLOW-001');
            $this->assertSame($businessCount + 1, Business::count());
            $this->assertSame(1, $owner->roleAssignments()->count());
            $this->getJson('/api/v1/business/onboarding/mine')->assertOk()
                ->assertJsonPath('id', $businessId)->assertJsonPath('name', $payload['name']);
        }

        $branchPayload = [
            'businessId' => $businessId, 'name' => 'Registration Flow First Branch',
            'sameLegalEntity' => true, 'serviceMode' => 'AT_LOCATION',
        ];
        $this->postJson('/api/v1/branches', $branchPayload)->assertForbidden();
        $this->postJson('/api/v1/business/'.$businessId.'/submit')->assertUnprocessable();
        $this->assertSame(BusinessStatus::Draft, $business->fresh()->status);

        $config = $this->getJson('/api/v1/business/onboarding/config')->assertOk()
            ->assertJsonPath('requiredDocuments', ['BUSINESS_LICENSE', 'OWNER_ID_CARD']);
        $types = $config->json('requiredDocuments');
        $documents = [];
        foreach ($types as $type) {
            $mediaId = $this->uploadPdf($business, $owner, $type);
            $documents[] = ['mediaId' => $mediaId, 'documentType' => $type, 'documentName' => $type];
        }
        foreach ([1, 2] as $save) {
            $this->patchJson($draftUrl, ['legalDocuments' => $documents, 'onboardingStep' => 4])
                ->assertOk()->assertJsonPath('id', $businessId)
                ->assertJsonCount(count($types), 'legalDocuments');
        }
        $this->assertSame(count($types), DB::table('beautybook_documents')
            ->where('business_id', $business->id)->whereNull('branch_id')->count());
        foreach ($documents as $document) {
            $this->assertSame(1, DB::table('beautybook_document_versions')
                ->where('media_id', $document['mediaId'])->count());
        }

        $this->postJson('/api/v1/business/'.$businessId.'/submit')->assertOk()
            ->assertJsonPath('id', $businessId)->assertJsonPath('status', 'PENDING_REVIEW');
        $this->postJson('/api/v1/branches', $branchPayload)->assertForbidden();
        $this->patchJson('/api/v1/business/'.$businessId.'/review', ['decision' => 'APPROVE'])
            ->assertForbidden();
        $this->assertDatabaseMissing('branches', ['business_id' => $business->id]);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@beautybook.local', 'password' => 'BeautyBook123!', 'workspace' => 'PLATFORM',
        ])->assertOk()->assertJsonPath('user.roles', ['PLATFORM_ADMIN']);
        $this->withHeader('Authorization', 'Bearer '.$adminLogin->json('accessToken'));
        $this->patchJson('/api/v1/business/'.$businessId.'/review', ['decision' => 'APPROVE'])
            ->assertOk()->assertJsonPath('id', $businessId)->assertJsonPath('status', 'APPROVED');
        $this->assertSame(BusinessStatus::Approved, $business->fresh()->status);
        $this->assertSame(['SUBMIT', 'APPROVE'], BusinessReviewEvent::where('business_id', $business->id)
            ->orderBy('id')->pluck('action')->all());
        $this->postJson('/api/v1/auth/logout')->assertOk();

        $ownerLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => 'BeautyBook123!', 'workspace' => 'SALON',
        ])->assertOk()->assertJsonPath('user.businessId', $businessId);
        $this->withHeader('Authorization', 'Bearer '.$ownerLogin->json('accessToken'));
        $created = $this->postJson('/api/v1/branches', $branchPayload)->assertOk()
            ->assertJsonPath('created', true)->assertJsonPath('requiresNewBusiness', false)
            ->assertJsonPath('branch.businessId', $businessId)->assertJsonPath('branch.reviewStatus', 'DRAFT');
        $branchId = $created->json('branch.id');
        $this->assertIsString($branchId);
        $this->assertMatchesRegularExpression('/^[1-9]\d*$/', $branchId);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'business_id' => $business->id]);
        $this->assertSame(1, Branch::where('business_id', $business->id)->count());
        $this->assertSame($businessCount + 1, Business::count());
        $this->assertSame(1, Business::where('owner_profile_id', $business->owner_profile_id)->count());
        $this->assertSame($assignment->id, $owner->roleAssignments()->sole()->id);
        $this->getJson('/api/v1/business/onboarding/mine')->assertOk()
            ->assertJsonPath('id', $businessId)->assertJsonPath('summary.branchCount', 1)
            ->assertJsonPath('branches.0.id', $branchId)->assertJsonPath('branches.0.businessId', $businessId);
        $this->getJson('/api/v1/branches/accessible')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $branchId)->assertJsonPath('0.businessId', $businessId);
        $this->getJson('/api/v1/branches/accessible/'.$branchId)->assertOk()
            ->assertJsonPath('id', $branchId)->assertJsonPath('businessId', $businessId);
        $foreignBranch = Branch::where('business_id', '<>', $business->id)->firstOrFail();
        $this->getJson('/api/v1/branches/accessible/'.$foreignBranch->id)->assertForbidden();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $scopedLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => 'BeautyBook123!', 'workspace' => 'SALON',
            'businessId' => $businessId, 'branchId' => $branchId,
        ])->assertOk()->assertJsonPath('user.businessId', $businessId)
            ->assertJsonPath('user.branchId', $branchId)->assertJsonPath('user.roles', ['BUSINESS_OWNER']);
        $this->withHeader('Authorization', 'Bearer '.$scopedLogin->json('accessToken'));
        $this->withHeaders(['X-Business-Id' => $businessId, 'X-Branch-Id' => $branchId])
            ->getJson('/api/v1/branches/accessible/'.$branchId)->assertOk()
            ->assertJsonPath('id', $branchId)->assertJsonPath('businessId', $businessId);
    }

    private function uploadPdf(Business $business, User $owner, string $type): string
    {
        $uploaded = $this->post('/api/v1/media/upload', [
            'file' => UploadedFile::fake()->createWithContent($type.'.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"),
            'entityType' => 'BUSINESS_DOCUMENT', 'entityId' => $business->id, 'businessId' => $business->id,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('visibility', 'PRIVATE')->assertJsonPath('mimeType', 'application/pdf')
            ->assertJsonPath('businessId', (string) $business->id)->assertJsonPath('branchId', null)
            ->assertJsonPath('entityType', 'BUSINESS_DOCUMENT')->assertJsonPath('url', null);
        $media = MediaFile::findOrFail($uploaded->json('id'));
        $this->assertSame($owner->id, $media->uploaded_by);
        $this->assertSame($business->id, $media->entity_id);
        Storage::disk('local')->assertExists($media->path);
        $this->getJson('/api/v1/media/'.$media->id.'/content')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        return (string) $media->id;
    }
}
