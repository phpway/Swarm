<?php
/**
 * vim:set ai si et ts=4 sw=4 syntax=php:
 *
 * reviews.php
 *
 * Queries the Swarm API and reports which reviews a specified user
 * needs to attend to.
 *
 * Required attention is determined by the following criteria:
 * - the user is a participant in a review
 * - and the user has not voted on the review
 * - and the user has not commented on the review
 * - or the user's comment on the review is a
 *   task that has been addressed and needs verification
 */

if (ini_set('track_errors', 1) === false) {
    echo "Warning: unable to track errors.\n";
}

# process command-line arguments
$options = getopt(
    'hs:r:v',
    array('help', 'swarm:', 'reviewer', 'verbose')
);

$swarm = '';
if (isset($options['s'])) {
    $swarm = $options['s'];
}
if (isset($options['swarm'])) {
    $swarm = $options['swarm'];
}
if (!$swarm) {
    usage('Swarm API URL not provided.');
}

$reviewer = '';
if (isset($options['r'])) {
    $reviewer = $options['r'];
}
if (isset($options['reviewer'])) {
    $reviewer = $options['reviewer'];
}
if (!$reviewer) {
    usage('Swarm reviewer not provided.');
}

$verbose = false;
if (isset($options['v']) || isset($options['verbose'])) {
    $verbose = true;
}

if (isset($options['h']) || isset($options['help'])) {
    usage();
}

function usage($message = null)
{
    if ($message) {
        echo "$message\n\n";
    }

    $script = basename(__FILE__);
    echo <<<EOU
$script: -s <Swarm URL> -u <API userid> -p <API user's password> \
  -r <reviewer userid to report on> -h

-s|--swarm     Swarm's URL (e.g. https://user@password:myswarm.url/)
-r|--reviewer  The reviewer to report on.
-h|--help      This help text.
-v|--verbose   Verbose output.

This script queries the Swarm API and reports on reviews that the
specified user needs to attend to.

Note: If your Helix Versioning Engine (p4d) has security level 3 set, you
cannot use a password to authenticate; you must acquire a host-unlocked
ticket from p4d, and use the ticket in place of a password when
communicating with the Swarm API connected to p4d.

EOU;
    exit;
}

function msg($message)
{
    global $verbose;

    if ($verbose) {
        echo $message;
    }
}

function call_api($url, $params)
{
    global $php_errormsg;

    $query    = http_build_query($params);
    $request  = $url . '?' . $query;
    $response = @file_get_contents($request);
    if ($php_errormsg) {
        echo "Unable to call api: $php_errormsg\n";
        exit;
    }

    $json = @json_decode($response, true);
    if ($php_errormsg) {
        echo "Unable to decode api response: $php_errormsg\n";
        exit;
    }

    return $json;
}

# remove trailing / from URL, if it exists
$swarm = rtrim(trim($swarm), '/');

# fetch the list of reviews
$reviews = call_api(
    "$swarm/api/v4/reviews",
    array(
        'hasReviewers' => 1, # only reviews with participants
        'participants' => array($reviewer), # only review for this reviewer
        'max'          => 9, # get plenty of reviews, if available
        'fields'       => array('id', 'description', 'commits'), # get these fields
    )
);

$report = array();
foreach ($reviews['reviews'] as $review) {
    if (is_null($review)) {
        continue;
    }

    $flag = false;
    msg('Review: ' . $review['id'] . ' ');

    # if the review is already committed, it likely does not need attention
    if (array_key_exists('commits', $review)
        && count($review['commits'])
    ) {
        msg("is committed, skipping...\n");
        continue;
    }

    # if the review has a vote from the reviewer, they are already aware
    if (array_key_exists('participants', $review)
        && array_key_exists('vote', $review['participants'][$reviewer])
    ) {
        msg("has vote from reviewer, skipping...\n");
        continue;
    }

    # if there are no open comments on the review, the reviewer's
    # attention is required
    if (array_key_exists('comments', $review)
        && $review['comments'][0] == 0
    ) {
        msg("has no open comments, skipping...\n");
        continue;
    }

    # fetch the comments for this review
    $comments = call_api(
        "$swarm/api/v4/comments",
        array(
            'topic' => 'reviews/' . $review['id'], # comments for this review
            'max'   => 9, # get plenty of comments, if available
        )
    );

    foreach ($comments['comments'] as $comment) {
        msg("\n  Comment: " . $comment['id'] . ' ');

        // skip over comments from other reviewers
        if (array_key_exists('user', $comment) && $reviewer != $comment['user']) {
            msg("is by another user, carry on...\n");
            continue;
        }

        # skip archived comments
        if (array_key_exists('flags', $comment)
            && count($comment['flags']) > 0
            && $comment['flags'][0] == 'closed'
        ) {
            msg("is archived, carry on...\n");
            continue;
        }

        # skip marked tasks
        if (array_key_exists('taskState', $comment)
            && ($comment['taskState'] == 'comment'
                || $comment['taskState'] == 'verified'
                || $comment['taskState'] == 'open'
            )
        ) {
            msg("reviewer's comment needs attention, carry on...\n");
            continue;
        }

        // anything else means that the reviewer's comment needs attention
        // by the reviewer
        $flag = true;
        msg("needs attention!\n");
        break;
    }

    // evaluation is complete. Does this review need attention?
    if ($flag) {
        $report[] = $review;
    }
}

if (count($report)) {
    echo "User '$reviewer' needs to attend to these reviews:\n";
    foreach ($report as $review) {
        $description = trim($review['description']);
        if (strlen($description) > 60) {
            $description = substr($description, 0, 60) . ' ...';
        }
        echo $review['id'] . ": $description\n";
    }
} else {
    echo "User '$reviewer' has no reviews to attend to.\n";
}
