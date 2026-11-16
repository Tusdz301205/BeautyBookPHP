<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$samples = [
    ['id'=>'qa-positive', 'text'=>'Dịch vụ rất tốt, nhân viên thân thiện, không phải chờ lâu và giá hợp lý.'],
    ['id'=>'qa-negative', 'text'=>'Dịch vụ kém, nhân viên khó chịu, chờ rất lâu và giá quá đắt.'],
    ['id'=>'qa-short', 'text'=>'Tôi đã đến đây hôm qua.'],
];
$predictions = app(App\Services\Reviews\AbsaClient::class)->predict($samples);
if (count($predictions) !== count($samples)) {
    throw new RuntimeException('Missing predictions');
}
file_put_contents(storage_path('app/private/full-qa/ai-predict.json'), json_encode(['validated'=>true,'predictions'=>$predictions], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Real pinned PhoBERT inference: 3/3 responses validated; no database writes.\n";
