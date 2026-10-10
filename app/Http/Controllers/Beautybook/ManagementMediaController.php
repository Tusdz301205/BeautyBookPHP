<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Models\{MediaFile,Combo};
use App\Services\Beautybook\{ApiScope,ManagementPresenter};
use App\Services\Media\MediaManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;

class ManagementMediaController extends Controller
{
    public function __construct(private ApiScope $scope,private ManagementPresenter $presenter) {}
    public function upload(Request $request,MediaManagementService $media): array
    {
        if($request->input('entityType')==='USER_AVATAR')return $this->avatar($request);
        $data=$request->validate(['file'=>'required|file|max:10240','entityType'=>'required|in:BUSINESS_LOGO,LEGAL_DOCUMENT,BUSINESS_IMAGE,BRANCH_IMAGE,SERVICE_IMAGE,STAFF_IMAGE,COMBO_IMAGE,BUSINESS_DOCUMENT,BRANCH_DOCUMENT,STAFF_DOCUMENT,BUSINESS,BRANCH,SERVICE,STAFF','entityId'=>'required|integer|min:1','businessId'=>'required|integer|exists:businesses,id','branchId'=>'nullable|integer|exists:branches,id']);
        $business=$this->scope->owner($request,$data['businessId']);$type=match($data['entityType']){'LEGAL_DOCUMENT'=>'BUSINESS_DOCUMENT','BUSINESS_LOGO'=>'BUSINESS_IMAGE',default=>$data['entityType']};$entityId=(int)$data['entityId'];
        $private=str_ends_with($type,'_DOCUMENT');$base=Str::before($type,'_');
        if($private||$base==='COMBO'){
            $branchId=null;
            if($base==='BRANCH')$branchId=$this->scope->branch($request,$entityId)->id;
            elseif($base==='BUSINESS')abort_unless($entityId===$business->id,403);
            elseif($base==='STAFF'){$staff=\App\Models\StaffProfile::findOrFail($entityId);abort_unless($staff->business_id===$business->id,403);$branchId=$staff->branch_id;}
            elseif($base==='COMBO'){$combo=Combo::findOrFail($entityId);abort_unless($combo->business_id===$business->id,403);$branchId=$combo->branch_id;}
            $file=$request->file('file');$mime=$file->getMimeType();
            if($private && $mime==='application/pdf')$extension='pdf';
            else {try{[$mime,$extension]=$media->validateContent($file);}catch(\DomainException $error){abort(422,$error->getMessage());}}
            $disk=$private?'local':'public';$path=$file->storeAs('media/'.$business->id,Str::uuid().'.'.$extension,$disk);abort_unless($path,500);
            try{$record=MediaFile::create(['disk'=>$disk,'path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$mime,'file_size'=>$file->getSize(),'uploaded_by'=>$request->user()->id,'business_id'=>$business->id,'branch_id'=>$branchId,'entity_type'=>$type,'entity_id'=>$entityId,'visibility'=>$private?'PRIVATE':'PUBLIC']);}
            catch(\Throwable $error){Storage::disk($disk)->delete($path);throw $error;}
        }else$record=$media->upload($request->user(),$business,$request->file('file'),$base,$entityId,'PUBLIC');
        return $this->presenter->media($record);
    }
    public function remove(Request $request,string $id)
    {
        $media=MediaFile::findOrFail($id);
        if(in_array($media->entity_type,['USER_AVATAR','CUSTOMER_AVATAR'])){abort_unless($media->uploaded_by===$request->user()->id && $media->entity_id===$request->user()->id,403);}
        else $this->scope->owner($request,$media->business_id);
        abort_if(DB::table('beautybook_document_versions')->where('media_id',$id)->exists(),409,'Tài liệu đã có lịch sử phiên bản. Hãy lưu trữ hồ sơ tài liệu.');
        DB::transaction(function () use($media){foreach(['business_images','branch_images','service_images','staff_images'] as $table)DB::table($table)->where('media_file_id',$media->id)->delete();$media->delete();});Storage::disk($media->disk)->delete($media->path);return response()->noContent();
    }
    public function content(Request $request,string $id)
    {
        $media=MediaFile::findOrFail($id);
        if($media->entity_type==='USER_AVATAR')abort_unless($media->uploaded_by===$request->user()->id && $media->entity_id===$request->user()->id,403);
        elseif($media->visibility!=='PUBLIC')$this->scope->owner($request,$media->business_id);
        abort_unless(Storage::disk($media->disk)->exists($media->path),404);
        return Storage::disk($media->disk)->response($media->path,$media->original_name,['Content-Type'=>$media->mime_type,'Cache-Control'=>$media->visibility==='PRIVATE'?'private, no-store':'public, max-age=3600']);
    }

    private function avatar(Request $request): array
    {
        $data=$request->validate(['file'=>'required|file|max:10240','entityId'=>'required|integer']);
        abort_unless((int)$data['entityId']===$request->user()->id,403);
        $file=$request->file('file');
        try{[$mime,$extension]=app(MediaManagementService::class)->validateContent($file);}catch(\DomainException $error){abort(422,$error->getMessage());}
        $path=$file->storeAs('avatars/'.$request->user()->id,Str::uuid().'.'.$extension,'local');abort_unless($path,500);
        try{$record=DB::transaction(function ()use($request,$file,$mime,$path){
            $user=\App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $record=MediaFile::create(['disk'=>'local','path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$mime,'file_size'=>$file->getSize(),'uploaded_by'=>$user->id,'business_id'=>null,'branch_id'=>null,'entity_type'=>'USER_AVATAR','entity_id'=>$user->id,'visibility'=>'PRIVATE']);
            $user->forceFill(['avatar_media_file_id'=>$record->id])->save();return $record;
        });}catch(\Throwable $error){Storage::disk('local')->delete($path);throw $error;}
        return $this->presenter->media($record);
    }
}
