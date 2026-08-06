<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Announcement;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard "announcement" widget data source. Read gated by announcements.view;
// write by announcements.manage.
class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::active()->orderByDesc('created_at')->get();

        return $this->sendOk($announcements->map(fn ($a) => $this->row($a)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAnnouncement($request);
        $announcement = Announcement::create([...$data, 'created_by' => $request->user()->id]);
        $this->audit->log('announcement.created', Announcement::class, $announcement->id);

        return $this->sendCreated($this->row($announcement));
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $data = $this->validateAnnouncement($request);
        $announcement->update($data);
        $this->audit->log('announcement.updated', Announcement::class, $announcement->id);

        return $this->sendOk($this->row($announcement->fresh()));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->delete();
        $this->audit->log('announcement.deleted', Announcement::class, $announcement->id);

        return $this->sendNoContent();
    }

    private function validateAnnouncement(Request $request): array
    {
        return $request->validate([
            'title'      => 'required|string|max:200',
            'body'       => 'required|string',
            'audience'   => 'nullable|string|max:60',
            'starts_at'  => 'nullable|date',
            'expires_at' => 'nullable|date',
        ]);
    }

    private function row(Announcement $a): array
    {
        return [
            'id'         => $a->id,
            'title'      => $a->title,
            'body'       => $a->body,
            'audience'   => $a->audience,
            'starts_at'  => $a->starts_at,
            'expires_at' => $a->expires_at,
            'created_by' => $a->created_by,
            'created_at' => $a->created_at,
            'updated_at' => $a->updated_at,
        ];
    }
}
