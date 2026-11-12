<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    public function list(Request $r, string $scope)
    {
        return $this->chat->list($r, ChatService::scopeName($scope));
    }

    public function open(Request $r, string $scope)
    {
        $data = $r->validate(['bookingId' => 'required|integer|min:1']);

        return $this->chat->open($r, ChatService::scopeName($scope), (int) $data['bookingId']);
    }

    public function messages(Request $r, string $scope, int $id)
    {
        return $this->chat->messages($r, ChatService::scopeName($scope), $id);
    }

    public function send(Request $r, string $scope, int $id)
    {
        $data = $r->validate(['clientMessageId' => 'required|uuid', 'body' => 'nullable|string|max:4000', 'bookingId' => 'nullable|integer|min:1', 'attachmentId' => 'nullable|integer|min:1']);

        return response()->json($this->chat->send($r, ChatService::scopeName($scope), $id, $data), 201);
    }

    public function read(Request $r, string $scope, int $id)
    {
        $data = $r->validate(['sequence' => 'required|integer|min:0']);

        return $this->chat->markRead($r, ChatService::scopeName($scope), $id, (int) $data['sequence']);
    }

    public function upload(Request $r, string $scope, int $id)
    {
        return response()->json($this->chat->upload($r, ChatService::scopeName($scope), $id, $r->file('file')), 201);
    }

    public function image(Request $r, string $scope, int $id, int $imageId)
    {
        $file = $this->chat->content($r, ChatService::scopeName($scope), $id, $imageId);

        return response($file['bytes'], 200, ['Content-Type' => $file['mimeType'], 'Content-Length' => (string) strlen($file['bytes']), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
