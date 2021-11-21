#!/usr/bin/env php
<?php declare(strict_types=1);

/*
 * https://github.com/hikari-no-yume/Twitter-archive-analyser
 * Copyright © 2021 hikari_no_yume.
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.

 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.

 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

// Twitter archives are big!
// Parts may be broken into multiple files. This budget is assuming we only load
// one of these huge parts at once.
ini_set("memory_limit", "1G");

function error(string $msg) {
    fwrite(STDERR, "$msg\n");
    exit(1);
}

if ($argc !== 2 || !is_dir($argv[1])) {
    error("Usage: $argv[0] my-Twitter-archive/data");
}

function getJSON(string $name, string $dirPath) {
    $path = "$dirPath/$name";
    $content = @file_get_contents($path);
    if ($content === FALSE) {
        error("Could not open file '$path', does it exist?");
    }
    // All the files look something like:
    //   window.YTD.tweet.part0 = [
    //       …
    //   ]
    // We want to strip out the JS/JSONP part to get to the inner JSON.
    $equals = " = ";
    $equalsPos = strpos($content, $equals);
    if ($equalsPos === FALSE || $equalsPos > 100) {
        error("$name file doesn't seem to be in the expected JSONP format.");
    }
    $content = substr($content, $equalsPos + strlen($equals));
    $content = json_decode($content);
    if ($content === NULL) {
        error("Couldn't decode JSON from $name file.");
    }
    echo "Memory used after loading '$name': ", memory_get_usage(), " bytes", PHP_EOL;
    return $content;
}

$users = [];

// To avoid unnecessary memory use, the JSON or its deserialisation should only
// be held in memory while we're iterating over the content.

foreach (getJSON("follower.js", $argv[1]) as $follower) {
    $follower = $follower->follower->accountId;
    $users[$follower] = $users[$follower] ?? [];
    $users[$follower]["follower"] = true;
}
foreach (getJSON("following.js", $argv[1]) as $follow) {
    $follow = $follow->following->accountId;
    $users[$follow]["following"] = true;
}

// Tweets file may be split into several parts (tweet.js, tweet-part1.js, etc)
$handle = opendir($argv[1]);
while (FALSE !== ($filename = readdir($handle))) {
    if (0 === strpos($filename, "tweet")) {
        foreach (getJSON($filename, $argv[1]) as $tweet) {
            $tweet = $tweet->tweet;
            $reply_to = $tweet->in_reply_to_user_id_str ?? NULL;
            if ($reply_to !== NULL && !$tweet->retweeted) {
                $users[$reply_to]["replies_to"][] = $tweet->id_str;
                // Array used as set
                $users[$reply_to]["usernames"][$tweet->in_reply_to_screen_name] = true;
            }
        }
    }
}
closedir($handle);

$mutuals = [];
foreach ($users as $user_id => $user) {
    if (empty($user["follower"]) || empty($user["following"])) {
        continue;
    }
    $mutuals[$user_id] = count(array_unique($user["replies_to"] ?? []));
}
arsort($mutuals);

echo "Number of mutuals: ", count($mutuals), PHP_EOL;

$nonMutualFollowers = [];
foreach ($users as $user_id => $user) {
    if (empty($user["follower"])) {
        continue;
    }
    if (!empty($user["following"])) {
        continue;
    }
    $nonMutualFollowers[$user_id] = count($user["replies_to"] ?? []);
}
arsort($nonMutualFollowers);

$nonzeroReplyNMFs = array_filter($nonMutualFollowers, function ($replyCount) {
    return $replyCount !== 0;
});

echo "Number of non-mutual followers you have replied to: ", count($nonzeroReplyNMFs), PHP_EOL;

$zeroReplyNMFs = array_filter($nonMutualFollowers, function ($replyCount) {
    return $replyCount === 0;
});

echo "Number of non-mutual followers you have never replied to: ", count($zeroReplyNMFs), PHP_EOL;

function writeTable(string $name, array $input) {
    $fp = @fopen("$name.html", "w");
    if ($fp === FALSE) {
        error("Couldn't open '$name.html' for writing.");
    }
    fwrite($fp, "<!doctype html>\n");
    fwrite($fp, "<meta charset=utf-8>\n");
    $title = htmlspecialchars($name) . " (total: " . count($input) . ")";
    fwrite($fp, "<title>$title</title>\n");
    fwrite($fp, "<h1>$title</h1>\n");
    fwrite($fp, "<table>\n");
    foreach ($input as $row) {
        fwrite($fp, "<tr>");
        foreach ($row as $cell) {
            $content = htmlspecialchars((string)$cell);
            if (0 === strpos($content, "http:") || 0 === strpos($content, "https:")) {
                $content = "<a href=\"$content\">$content</a>";
            }
            fwrite($fp, "<td>$content</td>");
        }
        fwrite($fp, "</tr>\n");
    }
    fwrite($fp, "</table>\n");
    echo "Table written to '$name.html'", PHP_EOL;
}

function prettifyRows(array $NMFs): array {
    global $users;

    $table = [];
    foreach ($NMFs as $user_id => $replyCount) {
        $table[] = [
            "ID: $user_id",
            // This is actually overkill because on my own account I never saw
            // someone with more than one username if I'd replied to them.
            // Presumably the usernames are the ones that were used at account
            // creation, and replies to suspended/deleted accounts are hidden?
            implode("/", array_keys($users[$user_id]["usernames"] ?? [])),
            "reply count: $replyCount",
            "https://twitter.com/intent/user?user_id=$user_id",
        ];
    }
    return $table;
}

writeTable("mutuals", prettifyRows($mutuals));

writeTable("nonzero-reply-non-mutual-followers", prettifyRows($nonzeroReplyNMFs));

writeTable("zero-reply-non-mutual-followers", prettifyRows($zeroReplyNMFs));
