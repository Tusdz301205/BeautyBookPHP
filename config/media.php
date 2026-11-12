<?php

// Private media (review photos, service result photos, consent proofs) is written to this disk.
// "local" keeps files under storage/app/private; "r2" uses the private bucket configured in filesystems.php.
// Files already on disk keep their own MediaFile.disk and stay readable after switching.
return [
    'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),
];
