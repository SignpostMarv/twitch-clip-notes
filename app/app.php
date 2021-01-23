<?php
/**
 * @author SignpostMarv
 */
declare(strict_types=1);

namespace SignpostMarv\TwitchClipNotes;

use function array_combine;
use function array_filter;
use const ARRAY_FILTER_USE_KEY;
use function array_keys;
use function array_map;
use function array_merge;
use function array_merge_recursive;
use function array_reverse;
use function array_unique;
use function array_values;
use function asort;
use function basename;
use function count;
use function date;
use function dirname;
use const FILE_APPEND;
use function file_get_contents;
use function file_put_contents;
use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTube_Playlist;
use Google_Service_YouTube_PlaylistItem;
use Google_Service_YouTube_PlaylistItemListResponse;
use Google_Service_YouTube_PlaylistListResponse;
use Google_Service_YouTube_PlaylistSnippet;
use Google_Service_YouTube_ResourceId;
use Google_Service_YouTube_VideoListResponse;
use Google_Service_YouTube_VideoSnippet;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_PRETTY_PRINT;
use function ksort;
use function mb_substr;
use function mkdir;
use function natsort;
use function realpath;
use RuntimeException;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strnatcasecmp;
use function strtotime;
use function uasort;
use function uksort;
use function usleep;
use function usort;

$transcriptions = in_array('--transcriptions', $argv, true);
$clear_nopes = in_array('--clear-nopes', $argv, true);
$unset_other_playlists = in_array('--unset-other-playlists', $argv, true);
$skip_fetch = in_array('--skip-fetch', $argv, true);

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/captions.php');
require_once(__DIR__ . '/global-topic-hierarchy.php');

$slugify = new Slugify();

$client = new Google_Client();
$client->setApplicationName('Twitch Clip Notes');
$client->setScopes([
	'https://www.googleapis.com/auth/youtube.readonly',
	'https://www.googleapis.com/auth/youtube.force-ssl',
]);

$client->setAuthConfig(__DIR__ . '/google-auth.json');
$client->setAccessType('offline');

$http = $client->authorize();

$service = new Google_Service_YouTube($client);

$other_playlists_on_channel = [];

$playlist_metadata = [
	realpath(
		__DIR__ .
		'/playlists/coffeestainstudiosdevs/satisfactory.json'
	) => __DIR__ . '/../coffeestainstudiosdevs/satisfactory/',
];

/** @var array<string, string> */
$playlists = [
];

foreach ($playlist_metadata as $metadata_path => $prepend_path) {
	$data = json_decode(file_get_contents($metadata_path), true);

	foreach ($data as $playlist_id => $markdown_path) {
		$playlists[$playlist_id] = $prepend_path . $markdown_path;
	}
}

/** @var array<string, array<string, string>> */
$videos = [];

/** @var array<string, list<string>> */
$video_tags = [];

$exclude_from_absent_tag_check = [
	'4_cYnq746zk', // official merch announcement video
];

/** @var list<string> */
$autocategorise = [];

$cache = json_decode(
	file_get_contents(__DIR__ . '/cache.json') ?: '[]',
	true
);

$update_cache = static function () use (&$cache) : void {
	file_put_contents(
		__DIR__ . '/cache.json',
		json_encode($cache, JSON_PRETTY_PRINT)
	);
};

if ($unset_other_playlists && isset($cache['playlists'])) {
	foreach (array_keys($cache['playlists']) as $playlist_id) {
		if ( ! isset($playlists[$playlist_id])) {
			unset($cache['playlists'][$playlist_id]);
		}
	}

	$update_cache();
}

foreach (($cache['videoTags'] ?? []) as $video_id => $data) {
	[$etag, $tags] = $data;

	$video_tags[$video_id] = $tags;
}

foreach (($cache['playlists'] ?? []) as $playlist_id => $data) {
	if (isset($playlists[$playlist_id])) {
		continue;
	}

	[$etag, $title, $video_ids] = $data;

	$other_playlists_on_channel[$playlist_id] = [$title, $video_ids];
}

$object_cache_captions = [];
$object_cache_videos = [];

$fetch_videos = static function (
	array $args,
	string $playlist_id,
	array &$videos,
	array &$video_tags
) use (
	$http,
	$playlists,
	$service,
	&$cache,
	$update_cache,
	&$object_cache_captions,
	$skip_fetch,
	&$fetch_videos
) : void {
	if ($skip_fetch) {
		return;
	}

	$args['playlistId'] = $playlist_id;
	$cache['playlists'] = $cache['playlists'] ?? [];
	$cache['playlistItems'] = $cache['playlistItems'] ?? [];
	$cache['captions'] = $cache['captions'] ?? [];
	$cache['videoTags'] = $cache['videoTags'] ?? [];

	/** @var Google_Service_YouTube_PlaylistItemListResponse */
	$response = $service->playlistItems->listPlaylistItems(
		implode(',', [
			'id',
			'snippet',
			'contentDetails',
		]),
		$args
	);

	/** @var iterable<Google_Service_YouTube_PlaylistItem> */
	$response_items = $response->items;

	foreach ($response_items as $video) {
		/** @var Google_Service_YouTube_VideoSnippet */
		$video_snippet = $video->snippet;

		/** @var Google_Service_YouTube_ResourceId */
		$video_snippet_resourceId = $video_snippet->resourceId;

		$video_id = $video_snippet_resourceId->videoId;

		if (
			! isset($cache['playlistItems'][$video_id])
			|| $cache['playlistItems'][$video_id][0] !== $video->etag
		) {
			/** @var Google_Service_YouTube_VideoListResponse */
			$tag_response = $service->videos->listVideos(
				'snippet',
				[
					'id' => $video_id,
				]
			);

			if (
				! isset($cache['videoTags'][$video_id])
				|| $cache['videoTags'][$video_id][0] !== $tag_response->etag
			) {
				/**
				 * @var array{0:object{
				 *	snippet:Google_Service_YouTube_VideoSnippet
				 * }}
				 */
				$tag_response_items = $tag_response->items;

				if (isset($tag_response_items[0]->snippet->tags)) {
					$cache['videoTags'][$video_id] = [
						$tag_response->etag,
						$tag_response_items[0]->snippet->tags,
					];
				} else {
					$cache['videoTags'][$video_id] = [
						$tag_response->etag,
						[],
					];
				}

				$update_cache();
			}

			$cache['playlistItems'][$video_id] = [
				$video->etag,
				$video_snippet->title,
			];

			$update_cache();
		}

		$videos[$playlist_id][$video_id] = $cache['playlistItems'][$video_id][1];
	}

	if (isset($response->nextPageToken)) {
		$args['pageToken'] = $response->nextPageToken;

		$fetch_videos($args, $playlist_id, $videos, $video_tags);
	}
};

$cache['playlists'] = $cache['playlists'] ?? [];

foreach ($playlists as $playlist_id => $markdown_path) {
	if ($skip_fetch) {
		continue;
	}

	$videos[$playlist_id] = [];

	/** @var Google_Service_YouTube_PlaylistListResponse */
	$response = $service->playlists->listPlaylists(
		'id,snippet',
		[
			'maxResults' => 1,
			'id' => $playlist_id,
		]
	);

	if (
		! isset($cache['playlists'][$playlist_id])
		|| $cache['playlists'][$playlist_id][0] !== $response->etag
	) {
		/** @var array{0:Google_Service_YouTube_Playlist} */
		$response_items = $response->items;

		/** @var Google_Service_YouTube_PlaylistSnippet */
		$playlist_snippet = $response_items[0]->snippet;

		$fetch_videos(
			[
				'maxResults' => 50,
			],
			$playlist_id,
			$videos,
			$video_tags
		);
		$cache['playlists'][$playlist_id] = [
			$response->etag,
			$playlist_snippet->title,
			array_keys($videos[$playlist_id]),
		];

		$update_cache();
	} else {
		foreach ($cache['playlists'][$playlist_id][2] as $video_id) {
			$videos[$playlist_id][$video_id] = $cache['playlistItems'][$video_id][1];
		}
	}

	if ( ! is_file($markdown_path)) {
		file_put_contents($markdown_path, "\n");

		$autocategorise[] = $playlist_id;
	}
}

$fetch_all_playlists = static function (array $args) use (
	&$other_playlists_on_channel,
	&$video_tags,
	$service,
	$fetch_videos,
	&$cache,
	$update_cache,
	&$videos,
	&$fetch_all_playlists,
	$skip_fetch,
	$playlists
) : void {
	if ($skip_fetch) {
		return;
	}

	/** @var Google_Service_YouTube_PlaylistListResponse */
	$response = $service->playlists->listPlaylists(
		'id,snippet',
		$args
	);

	/** @var list<Google_Service_YouTube_Playlist> */
	$response_items = $response->items;

	foreach ($response_items as $playlist) {
		if ( ! isset($playlists[$playlist->id])) {
			/** @var Google_Service_YouTube_PlaylistSnippet */
			$playlist_snippet = $playlist->snippet;

			$other_playlists_on_channel[$playlist->id] = [
				$playlist_snippet->title,
				[],
			];

			/** @var Google_Service_YouTube_PlaylistListResponse */
			$cache_response = $service->playlists->listPlaylists(
				'id,snippet',
				[
					'maxResults' => 1,
					'id' => $playlist->id,
				]
			);

			if (
				! isset($cache['playlists'][$playlist->id])
				|| $cache['playlists'][$playlist->id][0] !== $cache_response->etag
			) {
				$fetch_videos(
					['maxResults' => 50],
					$playlist->id,
					$other_playlists_on_channel[$playlist->id][1],
					$video_tags
				);

				$cache['playlists'][$playlist->id] = [
					$cache_response->etag,
					$playlist_snippet->title,
					array_keys($other_playlists_on_channel[$playlist->id][1][$playlist->id]),
				];

				$update_cache();

				$other_playlists_on_channel[$playlist->id][1] = array_keys(
					$other_playlists_on_channel[$playlist->id][1][$playlist->id]
				);
			} else {
				foreach ($cache['playlists'][$playlist->id][2] as $video_id) {
					$videos[$playlist->id][$video_id] = $cache['playlistItems'][$video_id][1];
					$other_playlists_on_channel[$playlist->id][1][] = $video_id;
				}
			}
		}
	}

	if (isset($response->nextPageToken)) {
		$args['pageToken'] = $response->nextPageToken;

		$fetch_all_playlists($args);
	}
};

$fetch_all_playlists([
	'channelId' => 'UCJamaIaFLyef0HjZ2LBEz1A',
	'maxResults' => 50,
]);

$global_topic_hierarchy = array_merge_recursive(
	$global_topic_hierarchy,
	$injected_global_topic_hierarchy
);

$injected_playlists = array_map(
	static function (string $filename) : string {
		return
			__DIR__ .
			'/../coffeestainstudiosdevs/satisfactory/' .
			$filename;
	},
	json_decode(
		file_get_contents(
			__DIR__ .
			'/playlists/coffeestainstudiosdevs/satisfactory.injected.json'
		),
		true
	)
);

$playlists = array_map(
	'realpath',
	array_merge($playlists, $injected_playlists)
);

asort($playlists);

$playlists = array_reverse($playlists);

$injected_cache = json_decode(
	file_get_contents(__DIR__ . '/cache-injection.json'),
	true
);

foreach ($injected_cache['playlists'] as $playlist_id => $injected_data) {
	if ( ! isset($videos[$playlist_id])) {
		$videos[$playlist_id] = [];
	}

	if (
		! isset(
			$other_playlists_on_channel[$playlist_id],
		)
		&& ! isset(
			$playlists[$playlist_id]
		)
		&& count($injected_data[2]) > 0
	) {
		$other_playlists_on_channel[$playlist_id] = [
			$injected_data[1],
			$injected_data[2],
		];
	} elseif (
		isset(
			$other_playlists_on_channel[$playlist_id],
		)
	) {
		$other_playlists_on_channel[$playlist_id][1] = array_merge(
			$other_playlists_on_channel[$playlist_id][1],
			$injected_data[2]
		);
	}

	foreach ($injected_data[2] as $video_id) {
		$videos[$playlist_id][$video_id] = (
			$injected_cache['playlistItems'][$video_id][1]
		);
	}
}

$cache = inject_caches($cache, $injected_cache);

$all_topic_ids = array_merge(
	array_keys($cache['playlists']),
	array_keys($cache['stubPlaylists'] ?? [])
);

$topic_nesting = [];

foreach ($all_topic_ids as $topic_id) {
	$topic_nesting['satisfactory'][$topic_id] = [
		'children' => [],
		'left' => -1,
		'right' => -1,
		'level' => -1,
	];
}

uksort(
	$topic_nesting['satisfactory'],
	static function (
		string $a,
		string $b
	) use ($cache) : int {
		$a_sorter = determine_topic_name($a, $cache);
		$b_sorter = determine_topic_name($b, $cache);

		return strnatcasecmp($a_sorter, $b_sorter);
	}
);

foreach ($global_topic_hierarchy as $basename => $topics) {

	foreach ($topics as $topic_id => $topic_ancestors) {
		if ( ! isset($topic_nesting[$basename][$topic_id])) {
			throw new RuntimeException('topic not already added!');
		}

		$topic_nesting[$basename][$topic_id]['level'] = count($topic_ancestors);

		$topic_ancestors = array_filter($topic_ancestors, 'is_string');

		$topic_ancestors = array_reverse($topic_ancestors);

		$topic_descendant_id = $topic_id;

		foreach ($topic_ancestors as $i => $topic_ancestor_name) {
			[$topic_ancestor_id] = determine_playlist_id(
				$topic_ancestor_name,
				[],
				$cache,
				$global_topic_hierarchy,
				$not_a_livestream,
				$not_a_livestream_date_lookup
			);

			if (
				! in_array(
					$topic_descendant_id,
					$topic_nesting[$basename][$topic_ancestor_id]['children'],
					true
				)
			) {

				$topic_nesting[$basename][$topic_ancestor_id]['children'][] =
					$topic_descendant_id
				;
			}

			$topic_descendant_id = $topic_ancestor_id;
		}
	}

	$basename_topics_nesting_ids = array_keys($topic_nesting[$basename]);

	$topic_nesting[$basename] = array_map(
		static function (
			array $data
		) use (
			$basename_topics_nesting_ids
		) : array {
			usort(
				$data['children'],
				static function (
					string $a,
					string $b
				) use (
					$basename_topics_nesting_ids
				) : int {
					return
						(int) array_search(
							$a,
							$basename_topics_nesting_ids
						) - (int) array_search(
							$b,
							$basename_topics_nesting_ids
						);
				}
			);

			return $data;
		},
		$topic_nesting[$basename]
	);

	$topic_nesting_roots = array_keys(array_filter(
		$topic_nesting[$basename],
		static function (array $maybe) : bool {
			return -1 === $maybe['level'];
		}
	));

	$current_left = 0;

	foreach ($topic_nesting_roots as $topic_id) {
		[$current_left, $topic_nesting[$basename]] = adjust_nesting(
			$topic_nesting[$basename],
			$topic_id,
			$current_left,
			$global_topic_hierarchy[$basename]
		);
	}

	$topics = $topic_nesting[$basename];

	uasort(
		$topics,
		static function (
			array $a,
			array $b
		) : int {
			return $a['left'] - $b['left'];
		}
	);

	$topic_nesting[$basename] = $topics;
}

file_put_contents(
	__DIR__ . '/topics-nested.json',
	json_encode($topic_nesting, JSON_PRETTY_PRINT)
);

uksort($videos, static function (string $a, string $b) use ($cache) : int {
	return strnatcasecmp(
		$cache['playlists'][$a][1],
		$cache['playlists'][$b][1]
	);
});

$videos = array_map(
	static function (array $in) : array {
		uasort($in, static function (string $a, string $b) : int {
			return strnatcasecmp($a, $b);
		});

		return $in;
	},
	$videos
);

$video_playlists = [];

foreach ($cache['playlists'] as $playlist_id => $data) {
	[,, $video_ids] = $data;

	foreach ($video_ids as $video_id) {
		if ( ! isset($video_playlists[$video_id])) {
			$video_playlists[$video_id] = [];
		}

		$video_playlists[$video_id][] = $playlist_id;
	}
}

foreach (array_keys($playlists) as $playlist_id) {
	$video_ids = $cache['playlists'][$playlist_id][2];

	usort($video_ids, static function (string $a, string $b) use ($cache) : int {
		return strnatcasecmp(
			$cache['playlistItems'][$a][1],
			$cache['playlistItems'][$b][1]
		);
	});

	$content_arrays = [
		'Related answer clips' => [],
		'Single video clips' => [],
	];

	$title_unix =
			(int) strtotime(
				mb_substr(
					basename($playlists[$playlist_id]),
					0,
					-3
				)
	);

	$title = (
		date(
			'F jS, Y',
			$title_unix
		) .
		'' .
		(
			isset($not_a_livestream[$playlist_id])
				? (' ' . $not_a_livestream[$playlist_id])
				: ' Livestream clips (non-exhaustive)'
		)
	);

	file_put_contents(
		$playlists[$playlist_id],
		(
			'---' . "\n"
			. sprintf('title: "%s"' . "\n", $title)
			. sprintf('date: "%s"' . "\n", date('Y-m-d', $title_unix))
			. 'layout: livestream' . "\n"
			. '---' . "\n"
			. '# '
			. $title
			. "\n"
		)
	);

	foreach ($video_ids as $video_id) {
		$found = false;

		foreach ($other_playlists_on_channel as $playlist_data) {
			[$title, $other_playlist_video_ids] = $playlist_data;

			if (in_array($video_id, $other_playlist_video_ids, true)) {
				$found = true;

				if ( ! isset($content_arrays['Related answer clips'][$title])) {
					$content_arrays['Related answer clips'][$title] = [];
				}
				$content_arrays['Related answer clips'][$title][] = $video_id;
			}
		}

		if ( ! $found) {
			$content_arrays['Single video clips'][] = $video_id;
		}
	}

	$basename_topic_nesting = $topic_nesting['satisfactory'];

	foreach (array_keys($content_arrays['Related answer clips']) as $title) {
		foreach (
			nesting_parents(
				determine_playlist_id(
					$title,
					[],
					$cache,
					$global_topic_hierarchy,
					$not_a_livestream,
					$not_a_livestream_date_lookup
				)[0],
				$basename_topic_nesting
			) as $title_parent_id
		) {
			$title_parent_title = determine_topic_name(
				$title_parent_id,
				$cache
			);

			if (
				! isset(
					$content_arrays[
						'Related answer clips'
						][
							$title_parent_title
							]
				)
			) {
				$content_arrays[
					'Related answer clips'
					][
						$title_parent_title
						] = [];
			}
		}
	}

	uksort(
		$content_arrays['Related answer clips'],
		static function (
			string $a,
			string $b
		) use (
			$basename_topic_nesting,
			$cache,
			$global_topic_hierarchy,
			$not_a_livestream,
			$not_a_livestream_date_lookup
		) : int {
			[$a_id] = determine_playlist_id(
				$a,
				[],
				$cache,
				$global_topic_hierarchy,
				$not_a_livestream,
				$not_a_livestream_date_lookup
			);
			[$b_id] = determine_playlist_id(
				$b,
				[],
				$cache,
				$global_topic_hierarchy,
				$not_a_livestream,
				$not_a_livestream_date_lookup
			);

			return
				$basename_topic_nesting[$a_id]['left'] -
				$basename_topic_nesting[$b_id]['left'];
		}
	);

	foreach ($content_arrays['Related answer clips'] as $title => $video_ids) {
		[$topic_id] = determine_playlist_id(
			$title,
			[],
			$cache,
			$global_topic_hierarchy,
			$not_a_livestream,
			$not_a_livestream_date_lookup
		);
		$depth = min(6, $basename_topic_nesting[$topic_id]['level'] + 2);

		file_put_contents(
			$playlists[$playlist_id],
			(
				"\n"
				. str_repeat('#', $depth)
				. ' ['
				. $title
				. '](./topics/'
				. topic_to_slug(
					$topic_id,
					$cache,
					$global_topic_hierarchy['satisfactory'],
					$slugify
				)[0]
				. '.md)'
				. "\n"
			),
			FILE_APPEND
		);

		foreach ($video_ids as $video_id) {
			file_put_contents(
				$playlists[$playlist_id],
				(
					'* '
					. maybe_transcript_link_and_video_url(
						$video_id,
						$cache['playlistItems'][$video_id][1]
					)
					. '' .
					"\n"
				),
				FILE_APPEND
			);
		}
	}

	if (count($content_arrays['Single video clips']) > 0) {
		file_put_contents(
			$playlists[$playlist_id],
			(
				''
				. '## Uncategorised'
				. "\n"
			),
			FILE_APPEND
		);
	}

	foreach ($content_arrays['Single video clips'] as $video_id) {
		file_put_contents(
			$playlists[$playlist_id],
			(
				'* ' .
				$cache['playlistItems'][$video_id][1] .
				''
				. ' '
				. video_url_from_id($video_id)
				. '' .
				"\n"
			),
			FILE_APPEND
		);
	}
}

$global_topic_hierarchy = array_map(
	static function (array $in) : array {
		uasort($in, static function (array $a, array $b) : int {
			return strnatcasecmp(implode('/', $a), implode('/', $b));
		});

		return $in;
	},
	$global_topic_hierarchy
);

$topics_json = [];
$playlist_topic_strings = [];
$playlist_topic_strings_reverse_lookup = [];

foreach ($all_topic_ids as $topic_id) {
	[$slug_string, $slug] = topic_to_slug(
		$topic_id,
		$cache,
		$global_topic_hierarchy['satisfactory'],
		$slugify
	);

	if ( ! isset($playlists[$topic_id])) {
		$topics_json[$slug_string] = $slug;
	}
	$playlist_topic_strings[$topic_id] = $slug_string;
	$playlist_topic_strings_reverse_lookup[$slug_string] = $topic_id;
}

ksort($topics_json);
ksort($playlist_topic_strings_reverse_lookup);

file_put_contents(__DIR__ . '/topics-satisfactory.json', json_encode($topics_json, JSON_PRETTY_PRINT));

$topic_slug_history = json_decode(
	file_get_contents(__DIR__ . '/topic-slug-history.json'),
	true
);

$now = time();

foreach ($playlist_topic_strings_reverse_lookup as $slug_string => $topic_id) {
	if ( ! isset($topics_json[$slug_string])) {
		continue;
	}

	if ( ! isset($topic_slug_history[$topic_id])) {
		$topic_slug_history[$topic_id] = [];
	}

	if ( ! isset($topic_slug_history[$topic_id][$slug_string])) {
		$topic_slug_history[$topic_id][$slug_string] = $now;
	}
}

$topic_slug_history = array_map(
	static function (array $to_sort) : array {
		asort($to_sort);

		return $to_sort;
	},
	$topic_slug_history
);

file_put_contents(__DIR__ . '/topic-slug-history.json', json_encode(
	$topic_slug_history,
	JSON_PRETTY_PRINT
));

if ($transcriptions) {
	$checked = 0;

	foreach (array_keys($playlists) as $playlist_id) {
		foreach ($cache['playlists'][$playlist_id][2] as $video_id) {
			$transcriptions_file = transcription_filename($video_id);

			$caption_lines = captions($video_id);

			if (count($caption_lines) < 1) {
				echo 'skipping captions for ', $video_id, "\n";

				continue;
			}

			$date = mb_substr(basename($playlists[$playlist_id]), 0, -3);

			$transcript_topic_strings = array_filter(
				$video_playlists[$video_id],
				static function (
					string $playlist_id
				) use ($playlist_topic_strings) : bool {
					return isset(
						$playlist_topic_strings[
							$playlist_id
						]
					);
				}
			);

			usort(
				$transcript_topic_strings,
				static function (
					string $a,
					string $b
				) use ($playlist_topic_strings) : int {
					return strnatcasecmp(
						$playlist_topic_strings[
							$a
						],
						$playlist_topic_strings[
							$b
						]
					);
				}
			);

			$transcript_topic_strings = array_values(
				$transcript_topic_strings
			);

			file_put_contents(
				$transcriptions_file,
				(
					'---' . "\n"
					. sprintf(
						'title: "%s"' . "\n",
						(
							date('F jS, Y', (int) strtotime($date))
							. (
								isset($not_a_livestream[$playlist_id])
									? (
										' '
										. $not_a_livestream[$playlist_id]
										. ' '
									)
									: ' Livestream '
							)
							. str_replace(
								'"',
								'\\"',
								$cache['playlistItems'][$video_id][1]
							)
						)
					)
					. sprintf(
						'date: "%s"' . "\n",
						date('Y-m-d', (int) strtotime($date))
					)
					. 'layout: transcript' . "\n"
					. sprintf(
						'topics:' . "\n" . '    - "%s"' . "\n",
						implode('"' . "\n" . '    - "', array_map(
							static function (
								string $playlist_id
							) use (
								$playlist_topic_strings
							) {
								return $playlist_topic_strings[
									$playlist_id
								];
							},
							$transcript_topic_strings
						))
					)
					. '---' . "\n"
					. '# [' . date('F jS, Y', (int) strtotime($date)) .
					''
					. ' '
					. (
						$not_a_livestream[$playlist_id]
							?? 'Livestream'
					)
					. '](../' . $date . '.md)' .
					"\n" .
					'## ' . $cache['playlistItems'][$video_id][1] .
					"\n" .
					''
					. video_url_from_id($video_id)
					. '' .
					''
					. "\n\n"
					. '### Topics' . "\n"
					. implode("\n", array_map(
						static function (
							string $playlist_id
						) use (
							$topics_json,
							$playlist_topic_strings
						) {
							return
								'* ['
								. implode(' > ', $topics_json[$playlist_topic_strings[
									$playlist_id
								]])
								. '](../topics/'
								. $playlist_topic_strings[
									$playlist_id
								]
								. '.md)';
						},
						array_filter(
							$video_playlists[$video_id],
							static function (
								string $playlist_id
							) use ($playlist_topic_strings) : bool {
								return isset(
									$playlist_topic_strings[
										$playlist_id
									]
								);
							}
						)
					))
					. "\n\n"
					. '### Transcript' . "\n"
					. '' .
					"\n"
				)
			);

			foreach ($caption_lines as $caption_line) {
				file_put_contents(
					$transcriptions_file,
					(
						'> ' . $caption_line .
						"\n" .
						'> ' .
						"\n"
					),
					FILE_APPEND
				);
			}
		}
	}

	echo
		sprintf(
			'%s subtitles checked of %s videos cached',
			$checked,
			count($cache['playlistItems'])
		),
		"\n";
}

/** @var list<string> */
$faq_dates = [];
$faq_patch = [];

$faq_playlist_data = [];
$faq_playlist_data_dates = [];

foreach (array_keys($playlist_metadata) as $metadata_path) {
	/** @var array<string, string> */
	$faq_playlist_data = json_decode(file_get_contents($metadata_path), true);

	foreach ($faq_playlist_data as $playlist_id => $filename) {
		$faq_playlist_date = mb_substr($filename, 0, -3);
		$faq_dates[] = $faq_playlist_date;
		$faq_playlist_data_dates[$playlist_id] = $faq_playlist_date;
	}
}

$faq_dates = array_unique($faq_dates);

natsort($faq_dates);

$faq_filepath = __DIR__ . '/../coffeestainstudiosdevs/satisfactory/FAQ.md';

usleep(100);

file_put_contents(
	$faq_filepath,
	(
		'---' . "\n"
		. 'title: "Frequently Asked Questions"' . "\n"
		. 'date: Last Modified' . "\n"
		. '---' . "\n"
	)
);

foreach ($cache['playlists'] as $cached_playlist_id => $cached_playlist_data) {
	if (isset($faq_playlist_data_dates[$cached_playlist_id])) {
		continue;
	}

	foreach ($cached_playlist_data[2] as $video_id) {
		if (
			isset($video_tags[$video_id])
			&& in_array('faq', $video_tags[$video_id], true)
		) {
			/** @var string|null */
			$faq_video_date = null;

			foreach (array_keys($faq_playlist_data) as $playlist_id) {
				if (
					isset(
						$cache['playlists'][$playlist_id],
						$faq_playlist_data_dates[$playlist_id]
					)
					&& in_array(
						$video_id,
						$cache['playlists'][$playlist_id][2],
						true
					)
				) {
					$faq_video_date = $faq_playlist_data_dates[$playlist_id];

					break;
				}
			}

			if (is_string($faq_video_date)) {
				if ( ! isset($faq_patch[$cached_playlist_data[1]])) {
					$faq_patch[$cached_playlist_data[1]] = [];
				}

				if (
					! isset(
						$faq_patch[$cached_playlist_data[1]][$faq_video_date]
					)
				) {
					$faq_patch[$cached_playlist_data[1]][$faq_video_date] = [];
				}

				$faq_patch[$cached_playlist_data[1]][$faq_video_date][] = (
					maybe_transcript_link_and_video_url(
						$video_id,
						$cache['playlistItems'][$video_id][1]
					)
				);
			}
		}
	}
}

$faq_topics = array_unique(
	array_merge(
		[],
		array_keys($faq_patch)
	)
);

natsort($faq_topics);

foreach ($faq_topics as $faq_topic) {
	file_put_contents(
		$faq_filepath,
		sprintf('# %s' . "\n\n", $faq_topic),
		FILE_APPEND
	);

	if (isset($faq_patch[$faq_topic])) {
		foreach ($faq_dates as $faq_date) {
			$lines = [];

			if (
				isset(
					$faq_patch[$faq_topic],
					$faq_patch[$faq_topic][$faq_date]
				)
			) {
				$patch_lines = $faq_patch[$faq_topic][$faq_date];
				natsort($patch_lines);

				$lines = array_merge($lines, $patch_lines);
			}

			if (count($lines) > 0) {
				file_put_contents(
					$faq_filepath,
					sprintf(
						'## %s' . "\n",
						date('F jS, Y', (int) strtotime($faq_date))
					),
					FILE_APPEND
				);

				foreach ($lines as $line) {
					file_put_contents(
						$faq_filepath,
						sprintf('* %s' . "\n", $line),
						FILE_APPEND
					);
				}

				file_put_contents(
					$faq_filepath,
					"\n",
					FILE_APPEND
				);
			}
		}

		foreach ($faq_patch[$faq_topic] as $k => $v) {
			if (is_array($v)) {
				natsort($v);
			}
		}
	}

	file_put_contents(
		$faq_filepath,
		"\n",
		FILE_APPEND
	);
}

foreach ($playlist_metadata as $json_file => $save_path) {
	$categorised = [];

	$data = json_decode(file_get_contents($json_file), true);

	if ($json_file === realpath(
		__DIR__ .
		'/playlists/coffeestainstudiosdevs/satisfactory.json'
	)) {
		$data = array_merge(
			$data,
			json_decode(
				file_get_contents(
					__DIR__ .
					'/playlists/coffeestainstudiosdevs/satisfactory.injected.json'
				),
				true
			)
		);
	}

	$basename = basename($save_path);

	$topic_hierarchy = $global_topic_hierarchy[$basename] ?? [];

	$file_path = $save_path . '/../' . $basename . '/topics.md';

	$data_by_date = [];

	$playlists_by_date = [];

	foreach ($data as $playlist_id => $filename) {
		$unix = strtotime(mb_substr($filename, 0, -3));
		$readable_date = date('F jS, Y', $unix);

		$data_by_date[$playlist_id] = [$unix, $readable_date];

		$playlists_by_date[$playlist_id] = $cache['playlists'][$playlist_id][2];
	}

	uksort(
		$playlists_by_date,
		static function (string $a, string $b) use ($data_by_date) : int {
			return $data_by_date[$b][0] - $data_by_date[$a][0];
		}
	);

	$playlist_ids = array_keys(($cache['playlists'] ?? []));

	foreach ($playlist_ids as $playlist_id) {
		if (isset($data[$playlist_id])) {
			continue;
		}

		$playlist_data = $cache['playlists'][$playlist_id];

		[, $playlist_title, $playlist_items] = $playlist_data;

		$slug = $topic_hierarchy[$playlist_id] ?? [];

		$categorised_dest = &$categorised;

		foreach ($slug as $slug_part) {
			if (is_int($slug_part)) {
				continue;
			}

			if ( ! isset($categorised_dest[$slug_part])) {
				$categorised_dest[$slug_part] = [];
			}

			$categorised_dest = &$categorised_dest[$slug_part];
		}

		$categorised_dest[] = $playlist_id;

		if (($slug[0] ?? '') !== $playlist_title) {
			$slug[] = $playlist_title;
		}

		$slug = array_filter(array_filter($slug, 'is_string'));

		$slug_count = count($slug);

		$slug_title = implode(' > ', $slug);

		$slug = array_map(
			[$slugify, 'slugify'],
			$slug
		);

		$slug_string = implode('/', $slug);

		$slug_path =
			realpath(
				$save_path
				. '/../'
				. $basename
				. '/topics/'
			)
			. '/'
			. $slug_string
			. '.md';

		$playlist_items_data = [];

		foreach ($playlists_by_date as $other_playlist_id => $other_playlist_items) {
			foreach ($playlist_items as $video_id) {
				if (in_array($video_id, $other_playlist_items, true)) {
					if ( ! isset($playlist_items_data[$other_playlist_id])) {
						$playlist_items_data[$other_playlist_id] = [];
					}
					$playlist_items_data[$other_playlist_id][] = $video_id;
				}
			}
		}

		$slug_dir = dirname($slug_path);

		if ( ! is_dir($slug_dir)) {
			mkdir($slug_dir, 0755, true);
		}

		file_put_contents(
			$slug_path,
			(
				'---' . "\n"
				. sprintf(
					'title: "%s"' . "\n",
					$slug_title
				)
				. 'date: Last Modified' . "\n"
				. '---' . "\n"
				. '[Topics]('
				. str_repeat('../', $slug_count)
				. 'topics.md)'
				. ' > '
				. $slug_title
				. "\n"
			)
		);

		foreach ($playlist_items_data as $playlist_id => $video_ids) {
			file_put_contents(
				$slug_path,
				(
					"\n" .
					'# ' .
					$data_by_date[$playlist_id][1] .
					''
					. ' '
					. (
						$not_a_livestream[$playlist_id]
							?? 'Livestream'
					)
					. '' .
					"\n"
				),
				FILE_APPEND
			);

			foreach ($video_ids as $video_id) {
				file_put_contents(
					$slug_path,
					(
						'* '
						. maybe_transcript_link_and_video_url(
							$video_id,
							$cache['playlistItems'][$video_id][1],
							$slug_count
						)
						. "\n"
					),
					FILE_APPEND
				);
			}
		}
	}

	file_put_contents(
		$file_path,
		(
			'---' . "\n"
			. 'title: "Browse Topics"' . "\n"
			. 'date: Last Modified' . "\n"
			. '---' . "\n"
		)
	);

	$basename_topic_nesting = $topic_nesting[$basename];

	$past_first = false;

	foreach ($basename_topic_nesting as $topic_id => $nesting_data) {
		if (isset($playlists[$topic_id])) {
			continue;
		}

		$include_heading = count($nesting_data['children']) > 0;

		if ($include_heading) {
			$depth = min(6, $nesting_data['level'] + 1);

			if ($past_first) {
				file_put_contents($file_path, "\n", FILE_APPEND);
			} else {
				$past_first = true;
			}

			file_put_contents(
				$file_path,
				(
					str_repeat('#', $depth)
					. ' ['
					. determine_topic_name($topic_id, $cache)
					. '](./topics/'
					. $playlist_topic_strings[$topic_id]
					. '.md)'
					. "\n"
				),
				FILE_APPEND
			);
		} else {
			file_put_contents(
				$file_path,
				(
					'* ['
					. determine_topic_name($topic_id, $cache)
					. '](./topics/'
					. $playlist_topic_strings[$topic_id]
					. '.md)'
					. "\n"
				),
				FILE_APPEND
			);
		}
	}
}

foreach ($playlist_metadata as $json_file => $save_path) {
	$data = json_decode(file_get_contents($json_file), true);

	if ($json_file === realpath(
		__DIR__ .
		'/playlists/coffeestainstudiosdevs/satisfactory.json'
	)) {
		$data = array_merge(
			$data,
			json_decode(
				file_get_contents(
					__DIR__ .
					'/playlists/coffeestainstudiosdevs/satisfactory.injected.json'
				),
				true
			)
		);
	}

	$basename = basename($save_path);

	$file_path = $save_path . '/../' . $basename . '/index.md';

	file_put_contents(
		$file_path,
		(
			'---' . "\n"
			. 'title: Browse' . "\n"
			. 'date: Last Modified' . "\n"
			. 'layout: index' . "\n"
			. '---' . "\n"
		)
	);

	$grouped = [];

	$sortable = [];

	foreach ($data as $filename) {
		$unix = strtotime(mb_substr($filename, 0, -3));
		$readable_month = date('F Y', $unix);
		$readable_date = date('F jS, Y', $unix);

		if ( ! isset($grouped[$readable_month])) {
			$grouped[$readable_month] = [];
			$sortable[$readable_month] = strtotime(date('Y-m-01', $unix));
		}

		$grouped[$readable_month][] = [$readable_date, $filename, $unix];
	}

	$grouped = array_map(
		static function (array $month) : array {
			usort(
				$month,
				static function (array $a, array $b) : int {
					return $a[2] - $b[2];
				}
			);

			return $month;
		},
		$grouped
	);

	uasort($sortable, static function (int $a, int $b) : int {
		return $b - $a;
	});

	foreach (array_keys($sortable) as $readable_month) {
		file_put_contents(
			$file_path,
			sprintf("\n" . '# %s' . "\n", $readable_month),
			FILE_APPEND
		);

		foreach ($grouped[$readable_month] as $line_data) {
			[$readable_date, $filename] = $line_data;

			file_put_contents(
				$file_path,
				sprintf(
					'* [%s](%s)' . "\n",
					$readable_date,
					$filename
				),
				FILE_APPEND
			);
		}
	}
}
