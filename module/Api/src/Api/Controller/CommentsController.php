<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Api\Controller;

use Api\AbstractApiController;
use Zend\Http\Request;
use Zend\View\Model\JsonModel;

/**
 * Swarm Comments
 *
 * @SWG\Resource(
 *   apiVersion="v3",
 *   basePath="/api/v3/"
 * )
 */
class CommentsController extends AbstractApiController
{
    /**
     * @SWG\Api(
     *     path="comments/",
     *     @SWG\Operation(
     *         method="GET",
     *         summary="Get List of Comments",
     *         notes="List comments.",
     *         nickname="getComments",
     *         @SWG\Parameter(
     *             name="after",
     *             description="A comment ID to seek to. Comments up to and including the specified
     *                          ID are excluded from the results and do not count towards `max`.
     *                          Useful for pagination. Commonly set to the `lastSeen` property from
     *                          a previous query.",
     *             paramType="query",
     *             type="integer",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="max",
     *             description="Maximum number of comments to return. This does not guarantee that
     *                          `max` comments are returned. It does guarantee that the number of
     *                          comments returned won't exceed `max`.",
     *             paramType="query",
     *             type="integer",
     *             defaultValue="100",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="topic",
     *             description="Only comments for given topic are returned.
     *                          Examples: `reviews/1234`, `changes/1234` or `jobs/job001234`.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="fields",
     *             description="An optional comma-separated list (or array) of fields to show for each comment.
     *                          Omitting this parameter or passing an empty value shows all fields.",
     *             paramType="query",
     *             type="string",
     *             required=false
     *         )
     *     )
     * )
     *
     *
     * @apiUsageExample Listing comments
     *
     *   To list comments:
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v3/comments\
     *   ?topic=reviews/911&max=2&fields=id,body,time,user"
     *   ```
     *
     *   Swarm responds with a list of the first two comments for review 911 and a `lastSeen` value for pagination:
     *
     *   ```json
     *   {
     *     "topic": "reviews/911",
     *     "comments": {
     *       "35": {
     *         "id": 35,
     *         "body": "Excitation thunder cats intelligent man braid organic bitters.",
     *         "time": 1461164347,
     *         "user": "bruno"
     *       },
     *       "39": {
     *         "id": 39,
     *         "body": "Chamber tote bag butcher, shirk truffle mode shabby chic single-origin coffee.",
     *         "time": 1461164347,
     *         "user": "swarm_user"
     *       }
     *     },
     *     "lastSeen": 39
     *   }
     *   ```
     *
     * @apiUsageExample Paginating a comment listing
     *
     *   To obtain the next page of a comments list (based on the previous example):
     *
     *   ```bash
     *   curl -u "username:password" "https://my-swarm-host/api/v3/comments\
     *   ?topic=reviews/911&max=2&fields=id,body,time,user&after=39"
     *   ```
     *
     *   Swarm responds with the second page of results, if any comments are present after the last seen comment:
     *
     *   ```json
     *   {
     *     "topic": "reviews/911",
     *     "comments": {
     *       "260": {
     *         "id": 260,
     *         "body": "Reprehensible do lore flank ham hock.",
     *         "time": 1461164349,
     *         "user": "bruno"
     *       },
     *       "324": {
     *         "id": 324,
     *         "body": "Sinter lo-fi temporary, nihilist tote bag mustache swag consequence interest flexible.",
     *         "time": 1461164349,
     *         "user": "bruno"
     *       }
     *     },
     *     "lastSeen": 324
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "topic": "",
     *       "comments": {
     *         "51": {
     *           "id": 51,
     *           "attachments": [],
     *           "body": "Short loin ground round sin reprehensible, venison west participle triple.",
     *           "context": [],
     *           "edited": null,
     *           "flags": [],
     *           "likes": [],
     *           "taskState": "comment",
     *           "time": 1461164347,
     *           "topic": "reviews/885",
     *           "updated": 1461164347,
     *           "user": "bruno"
     *         }
     *       },
     *       "lastSeen": 51
     *     }
     *
     *     `lastSeen` can often be used as an offset for pagination, by using the value
     *     in the `after` parameter of subsequent requests.
     *
     * @apiSuccessExample When no results are found, the `comments` array is empty:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "topic": "jobs/job000011",
     *       "comments": [],
     *       "lastSeen": null
     *     }
     *
     * @return  JsonModel
     */
    public function getList()
    {
        $request = $this->getRequest();
        $topic   = $request->getQuery('topic');
        $fields  = $request->getQuery('fields');

        $query = array(
            'after' => $request->getQuery('after'),
            'max'   => $request->getQuery('max', 100)
        );

        $result = $this->forward('Comments\Controller\Index', 'index', array('topic' => $topic), $query);

        return $this->getResponse()->isOk()
            ? $this->prepareSuccessModel($result, $fields)
            : $this->prepareErrorModel($result);
    }

    /**
     * @SWG\Api(
     *     path="comments/",
     *     @SWG\Operation(
     *         method="POST",
     *         summary="Add A Comment",
     *         notes="Add a comment to a topic (such as a review or a job)",
     *         nickname="addComment",
     *         @SWG\Parameter(
     *             name="topic",
     *             description="Topic to comment on.
     *                          Examples: `reviews/1234`, `changes/1234` or `jobs/job001234`.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="body",
     *             description="Content of the comment.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="taskState",
     *             description="Optional task state of the comment. Valid values when adding a comment are `comment`
     *                          and `open`. This creates a plain comment or opens a task, respectively.",
     *             paramType="form",
     *             type="string",
     *             required=false,
     *             defaultValue="comment"
     *         ),
     *         @SWG\Parameter(
     *             name="flags[]",
     *             description="Optional flags on the comment. Typically set to `closed` to archive a comment.",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         )
     *     )
     * )
     *
     * @apiUsageExample Create a comment on a review
     *
     *   To create a comment on a review:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "topic=reviews/2" \
     *        -d "body=This is my comment. It is an excellent comment. It contains a beginning, a middle, and an end." \
     *        "https://my-swarm-host/api/v3/comments"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 42,
     *       "attachments": [],
     *       "body": "This is my comment. It is an excellent comment. It contains a beginning, a middle, and an end.",
     *       "context": [],
     *       "edited": null,
     *       "flags": [],
     *       "likes": [],
     *       "taskState": "comment",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiUsageExample Open a task on a review
     *
     *   To create a comment on a review, and flag it as an open task:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -d "topic=reviews/2" \
     *        -d "taskState=open" \
     *        -d "body=If you could go ahead and attach a cover page to your TPS report, that would be great." \
     *        "https://my-swarm-host/api/v3/comments"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 43,
     *       "attachments": [],
     *       "body": "If you could go ahead and attach a cover page to your TPS report, that would be great.",
     *       "context": [],
     *       "edited": null,
     *       "flags": [],
     *       "likes": [],
     *       "taskState": "open",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Comment entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "comment": {
     *         "id": 42,
     *         "attachments": [],
     *         "body": "Best. Comment. EVER!",
     *         "context": [],
     *         "edited": null,
     *         "flags": [],
     *         "likes": [],
     *         "taskState": "comment",
     *         "time": 123456789,
     *         "topic": "reviews/2",
     *         "updated": 123456790,
     *         "user": "bruno"
     *       }
     *     }
     *
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function create($data)
    {
        $defaults = array('topic' => '', 'body' => '', 'taskState' => 'comment', 'flags' => array());
        $data    += $defaults;

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $services = $this->getServiceLocator();
        $query    = array(
            'bundleTopicComments' => false,
            'user'                => $services->get('user')->getId(),
        ) + array_intersect_key($data, $defaults);

        $result = $this->forward(
            'Comments\Controller\Index',
            'add',
            null,
            null,
            $query
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * @SWG\Api(
     *     path="comments/{id}",
     *     @SWG\Operation(
     *         method="PATCH",
     *         summary="Edit A Comment",
     *         notes="Edit a comment",
     *         nickname="editComment",
     *         @SWG\Parameter(
     *             name="id",
     *             description="ID of the comment to be edited",
     *             paramType="path",
     *             type="integer",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="topic",
     *             description="Topic to comment on.
     *                          Examples: `reviews/1234`, `changes/1234` or `jobs/job001234`.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="body",
     *             description="Content of the comment.",
     *             paramType="form",
     *             type="string",
     *             required=true
     *         ),
     *         @SWG\Parameter(
     *             name="taskState",
     *             description="Optional task state of the comment. Note that certain transitions (such as moving from
     *                          `open` to `verified`) are not possible without an intermediate step (`addressed`, in
     *                          this case).
     *                          Examples: `comment` (not a task), `open`, `addressed`, `verified`.",
     *             paramType="form",
     *             type="string",
     *             required=false
     *         ),
     *         @SWG\Parameter(
     *             name="flags[]",
     *             description="Optional flags on the comment. Typically set to `closed` to archive a comment.",
     *             paramType="form",
     *             type="array",
     *             required=false,
     *             @SWG\Items("string")
     *         )
     *     )
     * )
     *
     * @apiUsageExample Edit and archive a comment on a review
     *
     *   To edit and archive a comment on a review:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -X PATCH \
     *        -d "flags[]=closed" \
     *        -d "body=This comment wasn't as excellent as I may have lead you to believe. A thousand apologies." \
     *        "https://my-swarm-host/api/v3/comments/42"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 42,
     *       "attachments": [],
     *       "body": "This comment wasn't as excellent as I may have lead you to believe. A thousand apologies.",
     *       "context": [],
     *       "edited": 123466790,
     *       "flags": ["closed"],
     *       "likes": [],
     *       "taskState": "comment",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiUsageExample Flag a task as addressed on a review
     *
     *   To flag an open task as addressed on a review:
     *
     *   ```bash
     *   curl -u "username:password" \
     *        -X PATCH \
     *        -d "taskState=addressed" \
     *        "https://my-swarm-host/api/v3/comments/43"
     *   ```
     *
     *   JSON Response:
     *
     *   ```json
     *   {
     *     "comment": {
     *       "id": 43,
     *       "attachments": [],
     *       "body": "If you could go ahead and attach a cover page to your TPS report, that would be great.",
     *       "context": [],
     *       "edited": 123466790,
     *       "flags": ["closed"],
     *       "likes": [],
     *       "taskState": "comment",
     *       "time": 123456789,
     *       "topic": "reviews/2",
     *       "updated": 123456790,
     *       "user": "username"
     *     }
     *   }
     *   ```
     *
     * @apiSuccessExample Successful Response contains Comment entity:
     *     HTTP/1.1 200 OK
     *
     *     {
     *       "comment": {
     *         "id": 1,
     *         "attachments": [],
     *         "body": "Best. Comment. EVER!",
     *         "context": [],
     *         "edited": 123466790,
     *         "flags": [],
     *         "likes": [],
     *         "taskState": "comment",
     *         "time": 123456789,
     *         "topic": "reviews/42",
     *         "updated": 123456790,
     *         "user": "bruno"
     *       }
     *     }
     *
     * @param   int     $id
     * @param   mixed   $data
     * @return  JsonModel
     */
    public function patch($id, $data)
    {
        $this->getRequest()->setMethod(Request::METHOD_POST);

        // explicitly control the query params we forward to the legacy endpoint
        // if new features get added, we don't want them to suddenly appear
        $services = $this->getServiceLocator();
        $query    = array(
                'bundleTopicComments' => false,
                'user'                => $services->get('user')->getId(),
            ) + array_intersect_key($data, array_flip(array('topic', 'body', 'taskState', 'flags')));
        $result   = $this->forward(
            'Comments\Controller\Index',
            'edit',
            array('comment' => $id),
            null,
            $query
        );

        if (!$result->getVariable('isValid')) {
            $this->getResponse()->setStatusCode(400);
            return $this->prepareErrorModel($result);
        }

        return $this->prepareSuccessModel($result);
    }

    /**
     * Extends parent to provide special preparation of comment data
     *
     * @param   JsonModel|array     $model              A model to adjust prior to rendering
     * @param   string|array        $limitEntityFields  Optional comma-separated string (or array) of fields
     *                                                  When provided, limits entity output to specified fields.
     * @return  JsonModel           The adjusted model
     */
    public function prepareSuccessModel($model, $limitEntityFields = null)
    {
        $model = parent::prepareSuccessModel($model);

        // clean up model to minimize superfluous data
        unset($model->messages);
        unset($model->taskTransitions);
        unset($model->topic);

        // make adjustments to 'comment' entity if present
        $comment = $model->getVariable('comment');
        if ($comment) {
            $model->setVariable('comment', $this->normalizeComment($comment, $limitEntityFields));
        }

        // if a list of comments is present, normalize each one
        $comments = $model->getVariable('comments');
        if ($comments) {
            $comments = array_values($comments);
            foreach ($comments as $key => $comment) {
                $comments[$key] = $this->normalizeComment($comment, $limitEntityFields);
            }

            $model->setVariable('comments', $comments);
        }

        return $model;
    }

    protected function normalizeComment($comment, $limitEntityFields = null)
    {
        return $this->limitEntityFields($this->sortEntityFields($comment), $limitEntityFields);
    }
}
