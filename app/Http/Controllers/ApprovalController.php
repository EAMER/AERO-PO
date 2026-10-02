<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalDecision;
use App\Models\Approval;
use App\Models\ApprovalQuery;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function approve(Approval $approval, Request $request)
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->act(fn () => $this->approvals->approve($approval, Auth::user(), $data['note'] ?? null), $approval);
    }

    public function reject(Approval $approval, Request $request)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->act(fn () => $this->approvals->reject($approval, Auth::user(), $data['reason']), $approval);
    }

    public function query(Approval $approval, Request $request)
    {
        $data = $request->validate([
            'target_user_id' => 'required|exists:users,id',
            'question' => 'required|string|max:1000',
        ]);

        $target = User::findOrFail($data['target_user_id']);

        return $this->act(fn () => $this->approvals->query($approval, Auth::user(), $target, $data['question']), $approval);
    }

    public function answerQuery(ApprovalQuery $approvalQuery, Request $request)
    {
        $data = $request->validate(['answer' => 'required|string|max:2000']);

        return $this->act(fn () => $this->approvals->answerQuery($approvalQuery, Auth::user(), $data['answer']), null, $approvalQuery->approval->approvable_id);
    }

    private function act(callable $fn, ?Approval $approval, ?int $poIdFallback = null)
    {
        $poId = $approval?->approvable_id ?? $poIdFallback;

        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('requisitions.show', $poId)->with('status', 'Done.');
    }
}
