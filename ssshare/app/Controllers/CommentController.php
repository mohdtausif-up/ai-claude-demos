<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ShareRepository;
use App\Services\AccessPolicyService;
use App\Services\CommentService;
use App\Services\LinkGate;
use App\Services\ReviewService;
use App\Support\Auth;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

/** Comments, reactions and review decisions on a share. Interacting never consumes a view. */
final class CommentController extends Controller
{
    private CommentService $comments;

    public function __construct()
    {
        $this->comments = new CommentService();
    }

    /** @return array{0:array, 1:array} link and upload the visitor is allowed to interact with */
    private function accessible(string $id): array
    {
        $link = ShareRepository::findLink($id);
        $upload = $link !== null ? ShareRepository::upload((int) $link['upload_id']) : null;
        if ($link === null || $upload === null || $link['status'] !== 'active' || $upload['status'] !== 'active'
            || !AccessPolicyService::isUnlocked($link) || LinkGate::check($link, $upload) !== null) {
            throw HttpException::notFound('This share is not available.');
        }
        return [$link, $upload];
    }

    private function respond(Request $request, string $path, string $message, array $data = []): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['data' => $data + ['message' => $message]]);
        }
        $this->flash($message);
        return Response::redirect($path);
    }

    public function store(Request $request, array $params): Response
    {
        [, $upload] = $this->accessible($params['id']);
        $path = '/s/' . $params['id'] . '#comments';
        return $this->form($request, function () use ($request, $upload, $path) {
            $actor = CommentService::actor(Auth::user(), $request, $request->input('name'));
            $x = $request->input('pin_x');
            $y = $request->input('pin_y');
            $comment = $this->comments->add(
                $upload,
                $actor,
                (string) $request->input('body', ''),
                $x !== null && $x !== '' ? (float) $x : null,
                $y !== null && $y !== '' ? (float) $y : null,
                $request->input('parent'),
            );
            return $this->respond($request, $path, 'Comment posted.', ['id' => $comment['public_id']]);
        }, $path);
    }

    public function react(Request $request, array $params): Response
    {
        $this->accessible($params['id']);
        $comment = CommentService::find($params['comment']) ?? throw HttpException::notFound();
        $actor = CommentService::actor(Auth::user(), $request, null);
        $added = $this->comments->react($comment, $actor, (string) $request->input('emoji', ''));
        return $this->respond($request, '/s/' . $params['id'] . '#comment-' . $comment['public_id'], $added ? 'Reaction added.' : 'Reaction removed.', ['added' => $added]);
    }

    public function resolve(Request $request, array $params): Response
    {
        [, $upload] = $this->accessible($params['id']);
        $comment = CommentService::find($params['comment']) ?? throw HttpException::notFound();
        $resolved = ($request->input('resolved') ?? '1') !== '0';
        $this->comments->setResolved($comment, $upload, Auth::user(), $resolved);
        return $this->respond($request, '/s/' . $params['id'] . '#comment-' . $comment['public_id'], $resolved ? 'Comment resolved.' : 'Comment reopened.');
    }

    public function destroy(Request $request, array $params): Response
    {
        [, $upload] = $this->accessible($params['id']);
        $comment = CommentService::find($params['comment']) ?? throw HttpException::notFound();
        $this->comments->delete($comment, $upload, Auth::user(), CommentService::actor(null, $request, null)['hash']);
        return $this->respond($request, '/s/' . $params['id'] . '#comments', 'Comment deleted.');
    }

    public function review(Request $request, array $params): Response
    {
        [, $upload] = $this->accessible($params['id']);
        $path = '/s/' . $params['id'] . '#review';
        return $this->form($request, function () use ($request, $upload, $path) {
            $actor = CommentService::actor(Auth::user(), $request, $request->input('name'));
            (new ReviewService())->decide($upload, $actor, (string) $request->input('decision', ''), $request->input('note'));
            return $this->respond($request, $path, 'Thanks, your review was recorded.');
        }, $path);
    }
}
