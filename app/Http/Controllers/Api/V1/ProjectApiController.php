<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        // visibleTo, not all(): a client's token sees their own projects, and the
        // names of an agency's other clients are not theirs to read.
        $projects = Project::active()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $projects->map($this->summary(...))->values(),
        ]);
    }

    /**
     * One project plus the catalogs a script needs to write back: statuses (per
     * project) and labels (workspace-wide). Bound by key so a CLI can ask for WEB
     * without first listing every project for an id.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load(['statuses:id,project_id,name,category,position']);

        return response()->json([
            'data' => [
                ...$this->summary($project),
                'statuses' => $project->statuses->map(fn ($status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'category' => $status->category->value,
                    'open' => $status->category->isOpen(),
                ])->values(),
                'labels' => Label::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Label $label) => $label->only(['id', 'name']))
                    ->values(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Project $project): array
    {
        return [
            'id' => $project->id,
            'key' => $project->key,
            'slug' => $project->slug,
            'name' => $project->name,
            'description' => $project->description,
        ];
    }
}
