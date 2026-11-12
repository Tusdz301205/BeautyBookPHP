<?php

namespace App\Services\GlowMeImport;

use DateTimeImmutable;
use DomainException;

/** Native booking/engagement projection; the orchestrator archives the raw source. */
final class BookingImport
{
    private array $tables = [];

    private array $notes = [];

    public function run(ImportContext $c): void
    {
        $this->tables = $this->notes = [];
        foreach (['branches', 'customer_profiles', 'bookings', 'booking_services', 'services', 'staff_profiles',
            'recurring_booking_plans', 'appointment_change_requests', 'booking_violation_events', 'reviews',
            'roles', 'user_roles', 'promotion_services', 'promotion_combos'] as $table) {
            $this->tables[$table] = [];
            foreach ($c->source($table) as $row) {
                $row = (array) $row;
                if (isset($row['id'])) {
                    $this->tables[$table][$row['id']] = $row;
                } else {
                    $this->tables[$table][] = $row;
                }
            }
        }

        $this->promotions($c);
        $this->bookings($c);
        $this->contactsAndHistory($c);
        $this->changesAndViolations($c);
        $this->recurring($c);
        $this->reviewsAndPhotos($c);
        $this->images($c);
        $this->favoritesAndNotifications($c);

        $c->note('Bookings: source has no booking channel, per-service lifecycle, actual timing, buffers, or violation policy-version snapshot. Native channel uses ONLINE_WEB; service status follows parent lifecycle; actual timing stays UNKNOWN, buffers are zero, policy version is glowme-import-v1. Creator uses command actor, otherwise the linked customer user.');
        $c->note('Bookings: contact address, original history timestamps/reasons beyond 255 characters, violation points, recurring command receipts, promotion codes, and source-only lifecycle fields remain in the raw archive. Histories use occurred_at as native created_at. No session/token/push or notification-outbox jobs are replayed.');
        foreach ($this->notes as $message => $count) {
            $c->note($message.' Rows: '.$count.'.');
        }
    }

    private function bookings(ImportContext $c): void
    {
        $items = [];
        foreach ($this->tables['booking_services'] as $row) {
            $items[$row['booking_id']][] = $row;
        }
        foreach ($this->tables['bookings'] as $row) {
            $branch = $this->record('branches', $row['branch_id']);
            $customer = $this->record('customer_profiles', $row['customer_id']);
            $scope = ['business_id' => $this->id($c, 'businesses', $branch['business_id']),
                'branch_id' => $this->id($c, 'branches', $row['branch_id'])];
            $bookingId = $this->id($c, 'bookings', $row['id']);
            $c->insert('bookings', $scope + [
                'id' => $bookingId,
                'customer_profile_id' => $this->id($c, 'customer_profiles', $row['customer_id']),
                'created_by_user_id' => $this->id($c, 'users', $row['command_actor_id'] ?? $customer['user_id']),
                'booking_code' => $row['booking_code'], 'appointment_start_at' => $row['start_at'],
                'appointment_end_at' => $row['end_at'], 'status' => $row['status'], 'source' => 'ONLINE_WEB',
                'pending_expires_at' => $row['pending_expires_at'], 'currency' => 'VND',
                'total_amount' => $row['total_estimated_amount'], 'note' => $row['note'],
                'branch_location_snapshot' => $this->locationSnapshot($c, $row['branch_location_snapshot'] ?? null),
                'deleted_at' => $row['deleted_at'],
            ] + $this->timestamps($row));

            $lines = $items[$row['id']] ?? [];
            usort($lines, fn (array $a, array $b) => [$a['selection_order'], $a['unit_ordinal'], $a['id']]
                <=> [$b['selection_order'], $b['unit_ordinal'], $b['id']]);
            $snapshots = [];
            foreach ($lines as $sort => $line) {
                $service = $this->record('services', $line['service_id']);
                $staff = $this->record('staff_profiles', $line['staff_id']);
                if ($service['branch_id'] !== $row['branch_id'] || $staff['branch_id'] !== $row['branch_id']) {
                    throw new DomainException('Booking service/staff source scope does not match its booking.');
                }
                $serviceId = $this->id($c, 'services', $line['service_id']);
                $variantId = $this->optionalId($c, 'service_variants', $line['variant_id']);
                $staffId = $this->id($c, 'staff_profiles', $line['staff_id']);
                $status = match ($row['status']) {
                    'COMPLETED' => 'COMPLETED',
                    'CANCELLED', 'REJECTED', 'EXPIRED', 'NO_SHOW' => 'CANCELLED',
                    default => 'SCHEDULED',
                };
                $c->insert('booking_services', $scope + [
                    'id' => $this->id($c, 'booking_services', $line['id']), 'booking_id' => $bookingId,
                    'branch_service_offering_id' => $serviceId, 'business_service_id' => $serviceId,
                    'staff_profile_id' => $staffId, 'service_variant_id' => $variantId,
                    // Native price_at_booking is the gross unit price; final allocations live in the quote.
                    'price_at_booking' => $line['price_original_snapshot'], 'currency' => 'VND',
                    'duration_minutes' => $line['duration_snapshot'], 'buffer_before_minutes' => 0,
                    'buffer_after_minutes' => 0, 'service_name_snapshot' => $line['service_name_snapshot'],
                    'variant_name_snapshot' => $line['variant_name_snapshot'], 'sort_order' => $sort,
                    'status' => $status, 'item_start_at' => $line['start_at'], 'item_end_at' => $line['end_at'],
                    'occupied_start_at' => $line['start_at'], 'occupied_end_at' => $line['end_at'],
                    'revision' => 1, 'actual_timing_status' => 'UNKNOWN',
                ] + $this->timestamps($line));
                $snapshots[] = [
                    'booking_service_id' => $this->id($c, 'booking_services', $line['id']),
                    'branch_service_offering_id' => $serviceId, 'business_service_id' => $serviceId,
                    'service_variant_id' => $variantId, 'staff_profile_id' => $staffId,
                    'service_name' => $line['service_name_snapshot'], 'variant_name' => $line['variant_name_snapshot'],
                    'unit_price' => $line['price_original_snapshot'], 'allocated_amount' => $line['price_final_snapshot'],
                    'discount_amount' => $line['discount_snapshot'], 'duration_minutes' => $line['duration_snapshot'],
                    'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'sort_order' => $sort,
                    'item_start_at' => $line['start_at'], 'item_end_at' => $line['end_at'],
                    'selection_order' => $line['selection_order'], 'unit_ordinal' => $line['unit_ordinal'],
                    'category_id' => $this->id($c, 'service_categories', $line['category_snapshot_id']),
                    'category_name' => $line['category_name_snapshot'],
                    'combo_id' => $this->optionalId($c, 'combos', $line['combo_id']), 'combo_name' => $line['combo_name_snapshot'],
                    'promotion_id' => $this->optionalId($c, 'promotions', $line['promotion_id']),
                    'promotion_name' => $line['promotion_name_snapshot'], 'promotion_type' => $line['promotion_type_snapshot'],
                    'promotion_value' => $line['promotion_value_snapshot'], 'blocks_capacity' => $line['blocks_capacity'],
                ];
            }
            $c->insert('booking_pricing_snapshots', [
                // Derived one-to-one rows reuse their parent's source ID within their own table.
                'id' => $bookingId, 'booking_id' => $bookingId, 'currency' => 'VND',
                'subtotal_amount' => $row['total_original_amount'], 'discount_amount' => $row['total_discount_amount'],
                'final_amount' => $row['total_estimated_amount'], 'quote_version' => 'glowme-import-v1',
                'source_snapshot' => ['items' => $snapshots, 'combo_id' => $this->optionalId($c, 'combos', $row['combo_id']),
                    'recurring_booking_series_id' => $this->optionalId($c, 'recurring_booking_plans', $row['recurring_plan_id']),
                    'occurrence_ordinal' => $row['occurrence_ordinal'], 'source_version' => $row['version']],
            ] + $this->timestamps($row));
        }
    }

    private function contactsAndHistory(ImportContext $c): void
    {
        foreach ($c->source('booking_contacts') as $row) {
            $row = (array) $row;
            $c->insert('booking_contacts', ['id' => $this->id($c, 'booking_contacts', $row['id']),
                'booking_id' => $this->id($c, 'bookings', $row['booking_id']), 'full_name' => $row['name'],
                'phone' => $row['phone'], 'email' => $row['email']] + $this->timestamps($row));
        }
        foreach ($c->source('booking_status_histories') as $row) {
            $row = (array) $row;
            if (isset($row['reason']) && mb_strlen($row['reason']) > 255) {
                $this->note('Booking history reasons longer than the native column were truncated; full reasons are archived.');
            }
            $c->insert('booking_status_histories', ['id' => $this->id($c, 'booking_status_histories', $row['id']),
                'booking_id' => $this->id($c, 'bookings', $row['booking_id']),
                'actor_user_id' => $this->optionalId($c, 'users', $row['actor_id']),
                'from_status' => $row['from_status'], 'to_status' => $row['to_status'],
                'reason' => isset($row['reason']) ? mb_substr($row['reason'], 0, 255) : null,
                'created_at' => $row['occurred_at']]);
        }
    }

    private function changesAndViolations(ImportContext $c): void
    {
        foreach ($this->tables['appointment_change_requests'] as $row) {
            $booking = $this->record('bookings', $row['booking_id']);
            $customer = $this->record('customer_profiles', $booking['customer_id']);
            $type = $row['requested_by_id'] === $customer['user_id'] ? 'CUSTOMER' : $this->actorType($row['requested_by_id']);
            $c->insert('appointment_change_requests', ['id' => $this->id($c, 'appointment_change_requests', $row['id']),
                'booking_id' => $this->id($c, 'bookings', $row['booking_id']),
                'requested_by_user_id' => $this->id($c, 'users', $row['requested_by_id']), 'requested_by_type' => $type,
                'request_type' => $row['request_type'], 'proposed_start_at' => $row['proposed_start_at'],
                'proposed_end_at' => $row['proposed_end_at'],
                'proposed_staff_profile_id' => $this->optionalId($c, 'staff_profiles', $row['proposed_staff_id']),
                'reason' => $row['reason'], 'status' => $row['status'],
                'reviewed_by_user_id' => $this->optionalId($c, 'users', $row['reviewed_by_id']),
                'reviewed_at' => $row['reviewed_at'], 'review_note' => $row['review_note'], 'expires_at' => $row['expires_at'],
            ] + $this->timestamps($row));
        }
        $valid = [];
        foreach ($this->tables['booking_violation_events'] as $row) {
            $booking = $this->record('bookings', $row['booking_id']);
            $branch = $this->record('branches', $booking['branch_id']);
            $customer = $this->record('customer_profiles', $booking['customer_id']);
            $start = new DateTimeImmutable($booking['start_at']);
            $occurred = new DateTimeImmutable($row['occurred_at']);
            $request = isset($row['source_request_id']) ? $this->record('appointment_change_requests', $row['source_request_id']) : null;
            $evidence = $row['customer_id'] === $booking['customer_id'] && $row['business_id'] === $branch['business_id'];
            $evidence = $evidence && match ($row['type']) {
                'NO_SHOW' => $request === null && $occurred > $start,
                'LATE_CANCELLATION' => $request !== null && $request['booking_id'] === $row['booking_id']
                    && $request['request_type'] === 'CANCEL' && $request['requested_by_id'] === $customer['user_id']
                    && $row['recorded_by_id'] === $customer['user_id']
                    && new DateTimeImmutable($request['created_at']) == $occurred
                    && $occurred < $start && $occurred > $start->modify('-168 hours'),
                default => false,
            };
            $voided = $row['voided_at'] !== null;
            $voidValid = $voided ? isset($row['voided_by_id'], $row['void_reason']) && mb_strlen(trim($row['void_reason'])) >= 3
                : $row['voided_by_id'] === null && $row['void_reason'] === null;
            if (! $evidence || ! $voidValid || (! $voided && isset($valid['booking:'.$row['booking_id']]))) {
                $this->note('Violation events incompatible with native evidence/time/void/one-active-event constraints were archived only.');

                continue;
            }
            $c->insert('booking_violation_events', ['id' => $this->id($c, 'booking_violation_events', $row['id']),
                'booking_id' => $this->id($c, 'bookings', $row['booking_id']),
                'customer_profile_id' => $this->id($c, 'customer_profiles', $row['customer_id']),
                'business_id' => $this->id($c, 'businesses', $row['business_id']),
                'source_request_id' => $this->optionalId($c, 'appointment_change_requests', $row['source_request_id']),
                'kind' => $row['type'], 'occurred_at' => $row['occurred_at'], 'appointment_start_at' => $booking['start_at'],
                'recorded_by_user_id' => $this->id($c, 'users', $row['recorded_by_id']), 'policy_version' => 'glowme-import-v1',
                'voided_at' => $row['voided_at'], 'voided_by_user_id' => $this->optionalId($c, 'users', $row['voided_by_id']),
                'void_reason' => $row['void_reason'], 'created_at' => $row['created_at']]);
            $valid[$row['id']] = true;
            if (! $voided) {
                $valid['booking:'.$row['booking_id']] = true;
            }
        }
        foreach ($c->source('customer_booking_policies') as $row) {
            $row = (array) $row;
            $event = $this->tables['booking_violation_events'][$row['trigger_event_id'] ?? ''] ?? null;
            if (! $event || ! isset($valid[$event['id']]) || $event['voided_at'] !== null
                || $event['customer_id'] !== $row['customer_id'] || $event['business_id'] !== $row['business_id']
                || new DateTimeImmutable($row['ends_at']) != (new DateTimeImmutable($event['occurred_at']))->modify('+30 days')
                || new DateTimeImmutable($row['ends_at']) <= new DateTimeImmutable($row['starts_at'])) {
                $this->note('Customer booking policies without a compatible valid trigger and 30-day end date were archived only.');

                continue;
            }
            $c->insert('customer_booking_policies', ['id' => $this->id($c, 'customer_booking_policies', $row['id']),
                'customer_profile_id' => $this->id($c, 'customer_profiles', $row['customer_id']),
                'business_id' => $this->id($c, 'businesses', $row['business_id']), 'revision' => 0,
                'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
                'triggered_by_violation_event_id' => $this->id($c, 'booking_violation_events', $row['trigger_event_id']),
            ] + $this->timestamps($row));
        }
    }

    private function recurring(ImportContext $c): void
    {
        foreach ($this->tables['recurring_booking_plans'] as $row) {
            $minute = (int) $row['preferred_minute'];
            $c->insert('recurring_booking_series', ['id' => $this->id($c, 'recurring_booking_plans', $row['id']),
                'customer_profile_id' => $this->id($c, 'customer_profiles', $row['customer_id']),
                'branch_id' => $this->id($c, 'branches', $row['branch_id']), 'idempotency_key' => 'glowme:'.$row['id'],
                'payload_hash' => $row['request_fingerprint'], 'frequency' => $row['frequency'],
                'staff_mode' => $row['staff_mode'], 'status' => $row['status'], 'start_date' => $row['anchor_date'],
                'preferred_time' => sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60),
                'occurrence_count' => $row['occurrence_count'],
                'request_snapshot' => ['timezone' => $row['timezone'], 'policy_version' => $row['policy_version'],
                    'staff_profile_id' => $this->optionalId($c, 'staff_profiles', $row['staff_id']),
                    'combo_id' => $this->optionalId($c, 'combos', $row['combo_id']),
                    'command_actor_user_id' => $this->id($c, 'users', $row['command_actor_id'])],
            ] + $this->timestamps($row));
        }
        foreach ($this->tables['bookings'] as $row) {
            if ($row['recurring_plan_id'] === null) {
                continue;
            }
            $plan = $this->record('recurring_booking_plans', $row['recurring_plan_id']);
            if ($plan['customer_id'] !== $row['customer_id'] || $plan['branch_id'] !== $row['branch_id'] || $row['occurrence_ordinal'] === null) {
                throw new DomainException('Recurring booking source scope or ordinal is invalid.');
            }
            $c->insert('recurring_booking_occurrences', ['id' => $this->id($c, 'bookings', $row['id']),
                'recurring_booking_series_id' => $this->id($c, 'recurring_booking_plans', $row['recurring_plan_id']),
                'booking_id' => $this->id($c, 'bookings', $row['id']), 'sequence' => $row['occurrence_ordinal'],
                'scheduled_start_at' => $row['start_at'], 'status' => $row['status'] === 'CANCELLED' ? 'CANCELLED' : 'BOOKED',
            ] + $this->timestamps($row));
        }
    }

    private function reviewsAndPhotos(ImportContext $c): void
    {
        $ratings = [];
        foreach ($c->source('review_service_ratings') as $row) {
            $row = (array) $row;
            $line = $this->record('booking_services', $row['booking_service_id']);
            $review = $this->record('reviews', $row['review_id']);
            if ($line['booking_id'] !== $review['booking_id'] || $row['staff_id'] !== $line['staff_id']) {
                throw new DomainException('Review service rating source scope is invalid.');
            }
            $ratings[$row['review_id']][] = ['booking_service_id' => $this->id($c, 'booking_services', $row['booking_service_id']),
                'staff_profile_id' => $this->id($c, 'staff_profiles', $row['staff_id']),
                'business_service_id' => $this->id($c, 'services', $line['service_id']),
                'service_name' => $line['service_name_snapshot'], 'rating' => $row['rating'], 'comment' => $row['comment']];
        }
        foreach ($this->tables['reviews'] as $row) {
            if ($this->record('bookings', $row['booking_id'])['customer_id'] !== $row['customer_id']) {
                throw new DomainException('Review customer does not match its booking.');
            }
            $c->insert('reviews', ['id' => $this->id($c, 'reviews', $row['id']),
                'booking_id' => $this->id($c, 'bookings', $row['booking_id']),
                'customer_profile_id' => $this->id($c, 'customer_profiles', $row['customer_id']),
                'overall_rating' => $row['rating'], 'comment' => $row['comment'] ?? '', 'is_anonymous' => false,
                'service_ratings' => $ratings[$row['id']] ?? [], 'status' => $row['visibility'] === 'PUBLIC' ? 'APPROVED' : 'HIDDEN',
            ] + $this->timestamps($row));
        }
        foreach ($c->source('review_photos') as $row) {
            $row = (array) $row;
            $c->insert('review_photos', ['id' => $this->id($c, 'review_photos', $row['id']),
                'booking_id' => $this->id($c, 'bookings', $row['booking_id']),
                'media_file_id' => $this->id($c, 'media_files', $row['media_id']),
                'review_id' => $this->optionalId($c, 'reviews', $row['review_id']),
                'sort_order' => $row['review_id'] === null ? null : $row['sort_order'], 'created_at' => $row['created_at']]);
        }
    }

    private function images(ImportContext $c): void
    {
        foreach ($c->source('service_images') as $row) {
            $row = (array) $row;
            if ($row['deleted_at'] !== null) {
                $this->note('Deleted service images have no native soft-delete equivalent and were archived only.');

                continue;
            }
            if ($row['image_type'] === 'PROMOTIONAL') {
                if ($row['visibility'] !== 'PUBLIC' || $row['revoked_at'] !== null) {
                    $this->note('Private/revoked promotional images have no native visibility equivalent and were archived only.');

                    continue;
                }
                $c->insert('service_images', ['id' => $this->id($c, 'service_images', $row['id']),
                    'branch_service_offering_id' => $this->id($c, 'services', $row['service_id']),
                    'media_file_id' => $this->id($c, 'media_files', $row['media_id']), 'sort_order' => $row['sort_order'],
                ] + $this->timestamps($row));

                continue;
            }
            if ($row['image_type'] !== 'RESULT') {
                $this->note('Unknown service image types were archived only.');

                continue;
            }
            $booking = isset($row['booking_id']) ? $this->record('bookings', $row['booking_id']) : null;
            $provenance = $booking !== null
                ? $row['consent_subject_id'] === $booking['customer_id'] && $row['consent_subject_reference'] === null
                : $row['booking_service_id'] === null && $row['consent_subject_id'] === null
                    && trim($row['consent_subject_reference'] ?? '') !== '' && $row['proof_media_id'] !== null;
            $publishedAt = $row['visibility'] === 'PUBLIC' && $row['revoked_at'] === null ? $row['published_at'] : null;
            $publication = $publishedAt === null || (isset($row['published_by_id'], $row['consent_captured_at'],
                $row['consent_captured_by_id'], $row['consent_source'], $row['consent_version'])
                && new DateTimeImmutable($row['consent_captured_at']) <= new DateTimeImmutable($publishedAt));
            if (! $provenance || ! $publication) {
                $this->note('Result photos without native provenance/publication evidence were archived only.');

                continue;
            }
            if (isset($row['booking_service_id'])) {
                $line = $this->record('booking_services', $row['booking_service_id']);
                if ($line['booking_id'] !== $row['booking_id'] || $line['service_id'] !== $row['service_id']) {
                    throw new DomainException('Result photo booking-service scope is invalid.');
                }
            }
            $c->insert('result_photos', ['id' => $this->id($c, 'service_images', $row['id']),
                'branch_service_offering_id' => $this->id($c, 'services', $row['service_id']),
                'media_file_id' => $this->id($c, 'media_files', $row['media_id']),
                'booking_id' => $this->optionalId($c, 'bookings', $row['booking_id']),
                'booking_service_id' => $this->optionalId($c, 'booking_services', $row['booking_service_id']),
                'customer_profile_id' => $this->optionalId($c, 'customer_profiles', $row['consent_subject_id']),
                'subject_reference' => $row['consent_subject_reference'],
                'proof_media_id' => $this->optionalId($c, 'media_files', $row['proof_media_id']),
                'consent_captured_at' => $row['consent_captured_at'], 'consent_source' => $row['consent_source'],
                'consent_version' => $row['consent_version'],
                'consent_captured_by_id' => $this->optionalId($c, 'users', $row['consent_captured_by_id']),
                'published_at' => $publishedAt, 'published_by_id' => $this->optionalId($c, 'users', $row['published_by_id']),
                'revoked_at' => $row['revoked_at'], 'version' => 0,
            ] + $this->timestamps($row));
        }
    }

    private function promotions(ImportContext $c): void
    {
        $services = $combos = [];
        foreach ($this->tables['promotion_services'] as $row) {
            $services[$row['promotion_id']][] = $this->id($c, 'services', $row['service_id']);
        }
        foreach ($this->tables['promotion_combos'] as $row) {
            $combos[$row['promotion_id']][] = $this->id($c, 'combos', $row['combo_id']);
        }
        foreach ($c->source('promotions') as $row) {
            $row = (array) $row;
            $selected = $row['target_mode'] === 'SELECTED';
            $serviceIds = $selected ? ($services[$row['id']] ?? []) : [];
            $comboIds = $selected ? ($combos[$row['id']] ?? []) : [];
            $c->insert('promotions', ['id' => $this->id($c, 'promotions', $row['id']),
                'business_id' => $this->id($c, 'businesses', $row['business_id']), 'created_by_platform' => false,
                'name' => $row['name'], 'discount_type' => $row['type'] === 'PERCENT' ? 'PERCENTAGE' : 'FIXED_AMOUNT',
                'discount_value' => $row['value'], 'start_date' => $row['starts_at'], 'end_date' => $row['ends_at'],
                'status' => $row['active'] ? 'ACTIVE' : 'INACTIVE',
                'code' => $row['code'] === null ? null : strtoupper(trim($row['code'])), 'target_mode' => $row['target_mode'],
                'audience' => 'ALL', 'branch_ids' => $row['branch_id'] === null ? null : [$this->id($c, 'branches', $row['branch_id'])],
                'service_ids' => $serviceIds ?: null, 'combo_ids' => $comboIds ?: null,
                'auto_apply' => $row['auto_apply'], 'stacking_allowed' => false,
                // Source has no per-customer usage limit; native default 1 would change eligibility.
                'max_usage_per_customer' => 4294967295, 'deleted_at' => $row['deleted_at'],
            ] + $this->timestamps($row));
        }
    }

    private function favoritesAndNotifications(ImportContext $c): void
    {
        foreach ($c->source('customer_saved_services') as $row) {
            $row = (array) $row;
            // The source has a composite key: let the native surrogate ID auto-increment.
            $c->insert('saved_services', ['customer_profile_id' => $this->id($c, 'customer_profiles', $row['customer_id']),
                'branch_service_offering_id' => $this->id($c, 'services', $row['service_id'])] + $this->timestamps($row));
        }
        foreach ($c->source('notifications') as $row) {
            $row = (array) $row;
            $bookingId = $this->optionalId($c, 'bookings', $row['booking_id']);
            $requestId = $this->optionalId($c, 'appointment_change_requests', $row['change_request_id']);
            $c->insert('notifications', ['id' => $this->id($c, 'notifications', $row['id']),
                'user_id' => $this->id($c, 'users', $row['user_id']), 'type' => $row['type'], 'severity' => 'INFO',
                'title' => $row['title'], 'body' => $row['body'], 'is_read' => $row['read_at'] !== null,
                'read_at' => $row['read_at'], 'target_type' => $requestId !== null ? 'AppointmentChangeRequest' : ($bookingId !== null ? 'Booking' : null),
                'target_id' => $requestId !== null ? (string) $requestId : ($bookingId !== null ? (string) $bookingId : null),
                'action_url' => $bookingId !== null ? '/bookings?booking='.$bookingId : null,
                'related_booking_id' => $bookingId, 'dedupe_key' => $row['dedupe_key'],
                'metadata' => $requestId !== null ? ['change_request_id' => $requestId] : null, 'created_at' => $row['created_at']]);
        }
    }

    private function actorType(string $userId): string
    {
        $salon = false;
        foreach ($this->tables['user_roles'] as $grant) {
            if ($grant['user_id'] !== $userId) {
                continue;
            }
            $role = $this->record('roles', $grant['role_id'])['code'];
            if ($role === 'PLATFORM_ADMIN') {
                return 'ADMIN';
            }
            $salon = $salon || in_array($role, ['BUSINESS_OWNER', 'RECEPTIONIST'], true);
        }

        return $salon ? 'SALON' : throw new DomainException('Change requester has no source role compatible with a native requester type.');
    }

    private function locationSnapshot(ImportContext $c, mixed $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR) : (array) $snapshot;

        return ['addressLine' => $snapshot['address'],
            'districtId' => $this->optionalId($c, 'districts', $snapshot['districtId'] ?? null),
            'wardId' => $this->optionalId($c, 'wards', $snapshot['wardId'] ?? null)];
    }

    private function record(string $table, string $uuid): array
    {
        return $this->tables[$table][$uuid] ?? throw new DomainException('Missing source relation in '.$table.'.');
    }

    private function id(ImportContext $c, string $table, string $uuid): int
    {
        return $c->id($table, $uuid) ?? throw new DomainException('Missing deterministic source ID in '.$table.'.');
    }

    private function optionalId(ImportContext $c, string $table, ?string $uuid): ?int
    {
        return $uuid === null ? null : $this->id($c, $table, $uuid);
    }

    private function timestamps(array $row): array
    {
        return ['created_at' => $row['created_at'], 'updated_at' => $row['updated_at'] ?? $row['created_at']];
    }

    private function note(string $message): void
    {
        $this->notes[$message] = ($this->notes[$message] ?? 0) + 1;
    }
}
