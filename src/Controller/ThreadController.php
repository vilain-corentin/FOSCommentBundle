<?php

/*
 * This file is part of the FOSCommentBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FOS\CommentBundle\Controller;

use FOS\CommentBundle\FormFactory\CommentableThreadFormFactoryInterface;
use FOS\CommentBundle\FormFactory\CommentFormFactoryInterface;
use FOS\CommentBundle\FormFactory\DeleteCommentFormFactoryInterface;
use FOS\CommentBundle\FormFactory\ThreadFormFactoryInterface;
use FOS\CommentBundle\FormFactory\VoteFormFactoryInterface;
use FOS\CommentBundle\Model\CommentInterface;
use FOS\CommentBundle\Model\CommentManagerInterface;
use FOS\CommentBundle\Model\ThreadInterface;
use FOS\CommentBundle\Model\ThreadManagerInterface;
use FOS\CommentBundle\Model\VoteManagerInterface;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Restful controller for the Threads.
 *
 * @author Alexander <iam.asm89@gmail.com>
 */
class ThreadController extends AbstractFOSRestController
{
    public const VIEW_FLAT = 'flat';
    public const VIEW_TREE = 'tree';

    /**
     * Presents the form to use to create a new Thread.
     *
     * @Rest\Get("/threads/new", name="new_threads")
     */
    public function newThreadsAction(): Response
    {
        $form = $this->getThreadFormFactory()->createForm();

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                ],
                'template' => '@FOSComment/Thread/new.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Gets the thread for a given id.
     *
     * @Rest\Get("/threads/{id}", name="get_thread")
     */
    public function getThreadAction(string $id): Response
    {
        $manager = $this->getThreadManager();
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $view = View::create()
            ->setData(['thread' => $thread]);

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Gets the threads for the specified ids.
     *
     * @Rest\Get("/threads", name="get_threads")
     */
    public function getThreadsActions(Request $request): Response
    {
        $ids = $request->query->get('ids');

        if (null === $ids) {
            throw new NotFoundHttpException('Cannot query threads without id\'s.');
        }

        $threads = $this->getThreadManager()->findThreadsBy(['id' => $ids]);

        $view = View::create()
            ->setData(['threads' => $threads]);

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Creates a new Thread from the submitted data.
     *
     * @Rest\Post("/threads", name="post_threads")
     */
    public function postThreadsAction(Request $request): Response
    {
        $threadManager = $this->getThreadManager();
        $thread = $threadManager->createThread();
        $form = $this->getThreadFormFactory()->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (null !== $threadManager->findThreadById($thread->getId())) {
                $this->onCreateThreadErrorDuplicate($form);
            }

            // Add the thread
            $threadManager->saveThread($thread);
            $response = $this->getViewHandler()->handle($this->onCreateThreadSuccess($form));
            return $this->createRedirect($response);
        }

        return $this->getViewHandler()->handle($this->onCreateThreadError($form));
    }

    /**
     * Get the edit form the open/close a thread.
     *
     * @Rest\Get("/threads/{id}/commentable", name="edit_thread_commentable")
     */
    public function editThreadCommentableAction(Request $request, string $id): Response
    {
        $manager = $this->getThreadManager();
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $thread->setCommentable($request->query->get('value', 1));

        $form = $this->getCommentableThreadFormFactory()->createForm();
        $form->setData($thread);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'id' => $id,
                    'isCommentable' => $thread->isCommentable(),
                ],
                'template' => '@FOSComment/Thread/commentable.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Edits the thread.
     *
     * @Rest\Patch("/threads/{id}/commentable", name="patch_thread_commentable")
     */
    public function patchThreadCommentableAction(Request $request, string $id): Response
    {
        $manager = $this->getThreadManager();
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $form = $this->getCommentableThreadFormFactory()->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $manager->saveThread($thread);
            $response = $this->getViewHandler()->handle($this->onOpenThreadSuccess($form));
            return $this->createRedirect($response);
        }

        return $this->getViewHandler()->handle($this->onOpenThreadError($form));
    }

    /**
     * Presents the form to use to create a new Comment for a Thread.
     *
     * @Rest\Get("/threads/{id}/comments/new", name="new_thread_comments")
     */
    public function newThreadCommentsAction(Request $request, string $id): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        $comment = $this->getCommentManager()->createComment($thread);

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));

        $form = $this->getCommentFormFactory()->createForm();
        $form->setData($comment);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'first' => 0 === $thread->getNumComments(),
                    'thread' => $thread,
                    'parent' => $parent,
                    'id' => $id,
                ],
                'template' => '@FOSComment/Thread/comment_new.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Get a comment of a thread.
     *
     * @Rest\Get("/threads/{id}/comments/{commentId}", name="get_thread_comment")
     */
    public function getThreadCommentAction(string $id, string $commentId): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $this->getCommentManager()->findCommentById($commentId);
        $parent = null;

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $ancestors = $comment->getAncestors();
        if (count($ancestors) > 0) {
            $parent = $this->getValidCommentParent($thread, $ancestors[count($ancestors) - 1]);
        }

        $view = View::create()
            ->setData([
                'data' => [
                    'comment' => $comment,
                    'thread' => $thread,
                    'parent' => $parent,
                    'depth' => $comment->getDepth(),
                ],
                'template' => '@FOSComment/Thread/comment.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Get the delete form for a comment.
     *
     * @Rest\Get("/threads/{id}/comments/{commentId}/remove", name="remove_thread_comment")
     */
    public function removeThreadCommentAction(Request $request, string $id, string $commentId): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $this->getCommentManager()->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->getDeleteCommentFormFactory()->createForm();
        $comment->setState($request->query->get('value', $comment::STATE_DELETED));

        $form->setData($comment);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'id' => $id,
                    'commentId' => $commentId,
                ],
                'template' => '@FOSComment/Thread/comment_remove.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Edits the comment state.
     *
     * @Rest\Patch("/threads/{id}/comments/{commentId}/state", name="patch_thread_comment_state")
     */
    public function patchThreadCommentStateAction(Request $request, string $id, string $commentId): Response
    {
        $manager = $this->getCommentManager();
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $manager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->getDeleteCommentFormFactory()->createForm();
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $manager->saveComment($comment)) {
                $response = $this->getViewHandler()->handle($this->onRemoveThreadCommentSuccess($form, $id));
                return $this->createRedirect($response);
            }
        }

        return $this->getViewHandler()->handle($this->onRemoveThreadCommentError($form, $id));
    }

    /**
     * Presents the form to use to edit a Comment for a Thread.
     *
     * @Rest\Get("/threads/{id}/comments/{commentId}/edit", name="edit_thread_comment")
     */
    public function editThreadCommentAction(string $id, string $commentId): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $this->getCommentManager()->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->getCommentFormFactory()->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'comment' => $comment,
                ],
                'template' => '@FOSComment/Thread/comment_edit.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Edits a given comment.
     *
     * @Rest\Put("/threads/{id}/comments/{commentId}", name="put_thread_comments")
     */
    public function putThreadCommentsAction(Request $request, string $id, string $commentId): Response
    {
        $commentManager = $this->getCommentManager();

        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->getCommentFormFactory()->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                $response = $this->getViewHandler()->handle($this->onEditCommentSuccess($form, $id));
                return $this->createRedirect($response);
            }
        }

        return $this->getViewHandler()->handle($this->onEditCommentError($form, $id));
    }

    /**
     * Get the comments of a thread. Creates a new thread if none exists.
     *
     * @Rest\Get("/threads/{id}/comments", name="get_thread_comments")
     *
     * @todo Add support page/pagesize/sorting/tree-depth parameters
     */
    public function getThreadCommentsAction(Request $request, ValidatorInterface $validator, string $id): Response
    {
        $displayDepth = $request->query->get('displayDepth');
        $sorter = $request->query->get('sorter');
        $thread = $this->getThreadManager()->findThreadById($id);

        // We're now sure it is no duplicate id, so create the thread
        if (null === $thread) {
            $permalink = $request->query->get('permalink');

            $thread = $this->getThreadManager()
                ->createThread();
            $thread->setId($id);
            $thread->setPermalink($permalink);

            // Validate the entity
            $errors = $validator->validate($thread, null, ['NewThread']);
            if (count($errors) > 0) {
                $view = View::create()
                    ->setStatusCode(Response::HTTP_BAD_REQUEST)
                    ->setData([
                        'data' => [
                            'errors' => $errors,
                        ],
                        'template' => '@FOSComment/Thread/errors.html.twig',
                    ]
                );

                return $this->getViewHandler()->handle($view);
            }

            // Decode the permalink for cleaner storage (it is encoded on the client side)
            $thread->setPermalink(urldecode($permalink));

            // Add the thread
            $this->getThreadManager()->saveThread($thread);
        }

        $viewMode = $request->query->get('view', 'tree');
        switch ($viewMode) {
            case self::VIEW_FLAT:
                $comments = $this->getCommentManager()->findCommentsByThread($thread, $displayDepth, $sorter);

                // We need nodes for the api to return a consistent response, not an array of comments
                $comments = array_map(function ($comment) {
                    return ['comment' => $comment, 'children' => []];
                },
                    $comments
                );
                break;
            case self::VIEW_TREE:
            default:
                $comments = $this->getCommentManager()->findCommentTreeByThread($thread, $sorter, $displayDepth);
                break;
        }

        $view = View::create()
            ->setData([
                'data' => [
                    'comments' => $comments,
                    'displayDepth' => $displayDepth,
                    'sorter' => 'date',
                    'thread' => $thread,
                    'view' => $viewMode,
                ],
                'template' => '@FOSComment/Thread/comments.html.twig',
            ]
        );

        // Register a special handler for RSS. Only available on this route.
        if ('rss' === $request->getRequestFormat()) {
            $templatingHandler = function ($handler, $view) {
                $data = $view->getData();
                $data['template'] = '@FOSComment/Thread/thread_xml_feed.html.twig';

                $view->setData($data);

                return new Response($handler->renderTemplate($view, 'rss'), Response::HTTP_OK, $view->getHeaders());
            };

            $this->getViewHandler()->registerHandler('rss', $templatingHandler);
        }

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Creates a new Comment for the Thread from the submitted data.
     *
     * @Rest\Post("/threads/{id}/comments", name="post_thread_comments")
     */
    public function postThreadCommentsAction(Request $request, string $id): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        if (!$thread->isCommentable()) {
            throw new AccessDeniedHttpException(sprintf('Thread "%s" is not commentable', $id));
        }

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));
        $commentManager = $this->getCommentManager();
        $comment = $commentManager->createComment($thread, $parent);

        $form = $this->getCommentFormFactory()->createForm(null, ['method' => 'POST']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                $response = $this->getViewHandler()->handle($this->onCreateCommentSuccess($form, $id, $parent));
                return $this->createRedirect($response);
            }
        }

        return $this->getViewHandler()->handle($this->onCreateCommentError($form, $id, $parent));
    }

    /**
     * Get the votes of a comment.
     *
     * @Rest\Get("/threads/{id}/comments/{commentId}/votes", name="get_thread_comment_votes")
     */
    public function getThreadCommentVotesAction(string $id, mixed $commentId): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $this->getCommentManager()->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $view = View::create()
            ->setData([
                'data' => [
                    'commentScore' => $comment->getScore(),
                ],
                'template' => '@FOSComment/Thread/comment_votes.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Presents the form to use to create a new Vote for a Comment.
     *
     * @Rest\Get("/threads/{id}/comments/{commentId}/votes/new", name="new_thread_comment_votes")
     */
    public function newThreadCommentVotesAction(Request $request, string $id, string $commentId): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $this->getCommentManager()->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $vote = $this->getVoteManager()->createVote($comment);
        $vote->setValue($request->query->get('value', 1));

        $form = $this->getVoteFormFactory()->createForm();
        $form->setData($vote);

        $view = View::create()
            ->setData([
                'data' => [
                    'id' => $id,
                    'commentId' => $commentId,
                    'form' => $form->createView(),
                ],
                'template' => '@FOSComment/Thread/vote_new.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Creates a new Vote for the Comment from the submitted data.
     *
     * @Rest\Post("/threads/{id}/comments/{commentId}/votes", name="post_thread_comment_votes")
     */
    public function postThreadCommentVotesAction(Request $request, $id, $commentId): Response
    {
        $thread = $this->getThreadManager()->findThreadById($id);
        $comment = $this->getCommentManager()->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $voteManager = $this->getVoteManager();
        $vote = $voteManager->createVote($comment);

        $form = $this->getVoteFormFactory()->createForm();
        $form->setData($vote);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $voteManager->saveVote($vote);
            $response = $this->getViewHandler()->handle($this->onCreateVoteSuccess($form, $id, $commentId));
            return $this->createRedirect($response);
        }

        return $this->getViewHandler()->handle($this->onCreateVoteError($form, $id, $commentId));
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     */
    protected function onCreateCommentSuccess(FormInterface $form, string $id, CommentInterface $parent = null): View
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment', [
            'id' => $id,
            'commentId' => $form->getData()->getId()
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the form submission fails.
     */
    protected function onCreateCommentError(FormInterface $form, string $id, CommentInterface $parent = null): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $id,
                    'parent' => $parent,
                ],
                'template' => '@FOSComment/Thread/comment_new.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the thread view on a successful form submission.
     */
    protected function onCreateThreadSuccess(FormInterface $form): View
    {
        return View::createRouteRedirect('fos_comment_get_thread', [
            'id' => $form->getData()->getId()
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the form submission fails.
     */
    protected function onCreateThreadError(FormInterface $form): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                ],
                'template' => '@FOSComment/Thread/new.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the Thread creation fails due to a duplicate id.
     */
    protected function onCreateThreadErrorDuplicate(FormInterface $form): Response
    {
        return new Response(sprintf("Duplicate thread id '%s'.", $form->getData()->getId()), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Action executed when a vote was successfully created.
     *
     * @todo Think about what to show. For now the new score of the comment
     */
    protected function onCreateVoteSuccess(FormInterface $form, string $id, string $commentId): View
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment_votes', [
            'id' => $id,
            'commentId' => $commentId
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the form submission fails.
     */
    protected function onCreateVoteError(FormInterface $form, string $id, string $commentId): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'id' => $id,
                    'commentId' => $commentId,
                    'form' => $form,
                ],
                'template' => '@FOSComment/Thread/vote_new.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     */
    protected function onEditCommentSuccess(FormInterface $form, string $id): View
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment', [
            'id' => $id,
            'commentId' => $form->getData()->getId()
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the form submission fails.
     */
    protected function onEditCommentError(FormInterface $form, string $id): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'comment' => $form->getData(),
                ],
                'template' => '@FOSComment/Thread/comment_edit.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the open thread edit view on a successful form submission.
     */
    protected function onOpenThreadSuccess(FormInterface $form): View
    {
        return View::createRouteRedirect('fos_comment_edit_thread_commentable', [
            'id' => $form->getData()->getId(),
            'value' => !$form->getData()->isCommentable()
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the form submission fails.
     */
    protected function onOpenThreadError(FormInterface $form): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'id' => $form->getData()->getId(),
                    'isCommentable' => $form->getData()->isCommentable(),
                ],
                'template' => '@FOSComment/Thread/commentable.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     */
    protected function onRemoveThreadCommentSuccess(FormInterface $form, string $id): View
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment', [
            'id' => $id,
            'commentId' => $form->getData()->getId()
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns HTTP_BAD_REQUEST response when the form submission fails.
     */
    protected function onRemoveThreadCommentError(FormInterface $form, string $id): View
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $id,
                    'commentId' => $form->getData()->getId(),
                    'value' => $form->getData()->getState(),
                ],
                'template' => '@FOSComment/Thread/comment_remove.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Checks if a comment belongs to a thread. Returns the comment if it does.
     */
    private function getValidCommentParent(ThreadInterface $thread, null|int|string $commentId): ?CommentInterface
    {
        if (null !== $commentId) {
            $comment = $this->getCommentManager()->findCommentById($commentId);
            if (!$comment) {
                throw new NotFoundHttpException(
                    sprintf('Parent comment with identifier "%s" does not exist', $commentId)
                );
            }

            if ($comment->getThread() !== $thread) {
                throw new NotFoundHttpException('Parent comment is not a comment of the given thread.');
            }

            return $comment;
        }

        return null;
    }

    protected function createRedirect(Response $response): Response
    {
        $redirect = new RedirectResponse($response->headers->get('Location'));
        $content = $redirect->getContent();
        $response->setContent($content);
        $response->setStatusCode(302);

        return $response;
    }

    protected function getThreadManager(): ThreadManagerInterface
    {
        return $this->container->get('fos_comment.manager.thread');
    }

    protected function getCommentManager(): CommentManagerInterface
    {
        return $this->container->get('fos_comment.manager.comment');
    }

    protected function getVoteManager(): VoteManagerInterface
    {
        return $this->container->get('fos_comment.manager.vote');
    }

    protected function getThreadFormFactory(): ThreadFormFactoryInterface
    {
        return $this->container->get('fos_comment.form_factory.thread');
    }

    protected function getCommentableThreadFormFactory(): CommentableThreadFormFactoryInterface
    {
        return $this->container->get('fos_comment.form_factory.commentable_thread');
    }

    protected function getCommentFormFactory(): CommentFormFactoryInterface
    {
        return $this->container->get('fos_comment.form_factory.comment');
    }

    protected function getDeleteCommentFormFactory(): DeleteCommentFormFactoryInterface
    {
        return $this->container->get('fos_comment.form_factory.delete_comment');

    }

    protected function getVoteFormFactory(): VoteFormFactoryInterface
    {
        return $this->container->get('fos_comment.form_factory.vote');
    }
}
