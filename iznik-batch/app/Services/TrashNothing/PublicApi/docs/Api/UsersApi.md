# OpenAPI\Client\UsersApi

Retrieve and update user data.

All URIs are relative to https://trashnothing.com/api/v1.4, except if the operation defines another base path.

| Method | HTTP request | Description |
| ------------- | ------------- | ------------- |
| [**getUserPosts()**](UsersApi.md#getUserPosts) | **GET** /users/{user_id}/posts | List posts by a user |
| [**searchUserPosts()**](UsersApi.md#searchUserPosts) | **GET** /users/{user_id}/posts/search | Search posts by a user |


## `getUserPosts()`

```php
getUserPosts($user_id, $types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts): \OpenAPI\Client\Model\GetUserPosts200Response
```

List posts by a user

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\UsersApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$user_id = 'user_id_example'; // string | The user ID of the user whose posts will be retrieved. Using 'me' as the user_id will return the posts for the current user.
$types = 'types_example'; // string | A comma separated list of the post types to return.  The available post types are: offer, wanted, admin
$sources = 'sources_example'; // string | A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or all the open archive groups the requested user has posted to if latitude, longitude and radius aren't passed). <br /><br /> NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required.
$sort_by = 'date'; // string | How to sort the posts that are returned.  One of: date, active, distance <br /><br /> Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first.
$group_ids = 'The group IDs of every group the current user is a member of.'; // string | A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the 'groups' source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). <br /><br /> NOTE: For requests using an api key instead of oauth, this field is required if the 'groups' source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). <br /><br/> *To determine which group IDs were used and which were discarded, use the group_ids field in the response.
$per_page = 20; // int | The number of posts to return per page (must be >= 1 and <= 100).
$page = 1; // int | The page of posts to return.
$device_pixel_ratio = 1.0; // float | Client device pixel ratio used to determine thumbnail size (default 1.0).
$latitude = 3.4; // float | The latitude of a point around which to return posts.
$longitude = 3.4; // float | The longitude of a point around which to return posts.
$radius = 3.4; // float | The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned.
$date_min = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts newer than or equal to this UTC date and time will be returned.
$date_max = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts older than this UTC date and time will be returned.
$outcomes = 'outcomes_example'; // string | A comma separated list of the post outcomes to return.  The available post outcomes are: satisfied, withdrawn <br /><br /> There are also a couple special values that can be passed.  If set to an empty string (the default), only posts that are not satisfied and not withdrawn and not expired are returned. If set to 'all', all posts will be returned no matter what outcome the posts have. If set to 'not-promised', only posts that are not satisfied ant not withdrawn and not expired and not promised are returned.
$include_reposts = 1; // int | If set to 1 (the default), posts that are reposts will be included. If set to 0, reposts will be excluded. See the repost_count field of post objects for details about how reposts are identified.

try {
    $result = $apiInstance->getUserPosts($user_id, $types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling UsersApi->getUserPosts: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **user_id** | **string**| The user ID of the user whose posts will be retrieved. Using &#39;me&#39; as the user_id will return the posts for the current user. | |
| **types** | **string**| A comma separated list of the post types to return.  The available post types are: offer, wanted, admin | |
| **sources** | **string**| A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or all the open archive groups the requested user has posted to if latitude, longitude and radius aren&#39;t passed). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required. | |
| **sort_by** | **string**| How to sort the posts that are returned.  One of: date, active, distance &lt;br /&gt;&lt;br /&gt; Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first. | [optional] [default to &#39;date&#39;] |
| **group_ids** | **string**| A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the &#39;groups&#39; source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, this field is required if the &#39;groups&#39; source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br/&gt; *To determine which group IDs were used and which were discarded, use the group_ids field in the response. | [optional] [default to &#39;The group IDs of every group the current user is a member of.&#39;] |
| **per_page** | **int**| The number of posts to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 100). | [optional] [default to 20] |
| **page** | **int**| The page of posts to return. | [optional] [default to 1] |
| **device_pixel_ratio** | **float**| Client device pixel ratio used to determine thumbnail size (default 1.0). | [optional] [default to 1.0] |
| **latitude** | **float**| The latitude of a point around which to return posts. | [optional] |
| **longitude** | **float**| The longitude of a point around which to return posts. | [optional] |
| **radius** | **float**| The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned. | [optional] |
| **date_min** | **\DateTime**| Only posts newer than or equal to this UTC date and time will be returned. | [optional] |
| **date_max** | **\DateTime**| Only posts older than this UTC date and time will be returned. | [optional] |
| **outcomes** | **string**| A comma separated list of the post outcomes to return.  The available post outcomes are: satisfied, withdrawn &lt;br /&gt;&lt;br /&gt; There are also a couple special values that can be passed.  If set to an empty string (the default), only posts that are not satisfied and not withdrawn and not expired are returned. If set to &#39;all&#39;, all posts will be returned no matter what outcome the posts have. If set to &#39;not-promised&#39;, only posts that are not satisfied ant not withdrawn and not expired and not promised are returned. | [optional] |
| **include_reposts** | **int**| If set to 1 (the default), posts that are reposts will be included. If set to 0, reposts will be excluded. See the repost_count field of post objects for details about how reposts are identified. | [optional] [default to 1] |

### Return type

[**\OpenAPI\Client\Model\GetUserPosts200Response**](../Model/GetUserPosts200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `searchUserPosts()`

```php
searchUserPosts($user_id, $search, $types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts): \OpenAPI\Client\Model\SearchUserPosts200Response
```

Search posts by a user

Searching posts takes the same arguments as listing posts except for the addition of the search and sort_by parameters.

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\UsersApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$user_id = 'user_id_example'; // string | The user ID of the user whose posts will be retrieved. Using 'me' as the user_id will return the posts for the current user.
$search = 'search_example'; // string | The search query used to find posts.
$types = 'types_example'; // string | A comma separated list of the post types to return.  The available post types are: offer, wanted, admin
$sources = 'sources_example'; // string | A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or all the open archive groups the requested user has posted to if latitude, longitude and radius aren't passed). <br /><br /> NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required.
$sort_by = 'relevance'; // string | How to sort the posts that are returned.  One of: relevance, date, active, distance <br /><br /> Relevance sorting will sort the posts that best match the search query first. Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first.
$group_ids = 'The group IDs of every group the current user is a member of.'; // string | A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the 'groups' source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). <br /><br /> NOTE: For requests using an api key instead of oauth, this field is required if the 'groups' source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). <br /><br/> *To determine which group IDs were used and which were discarded, use the group_ids field in the response.
$per_page = 20; // int | The number of posts to return per page (must be >= 1 and <= 100).
$page = 1; // int | The page of posts to return.
$device_pixel_ratio = 1.0; // float | Client device pixel ratio used to determine thumbnail size (default 1.0).
$latitude = 3.4; // float | The latitude of a point around which to return posts.
$longitude = 3.4; // float | The longitude of a point around which to return posts.
$radius = 3.4; // float | The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned.
$date_min = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts newer than or equal to this UTC date and time will be returned.
$date_max = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts older than this UTC date and time will be returned.
$outcomes = 'outcomes_example'; // string | A comma separated list of the post outcomes to return.  The available post outcomes are: satisfied, withdrawn <br /><br /> There are also a couple special values that can be passed.  If set to an empty string (the default), only posts that are not satisfied and not withdrawn and not expired are returned. If set to 'all', all posts will be returned no matter what outcome the posts have. If set to 'not-promised', only posts that are not satisfied ant not withdrawn and not expired and not promised are returned.
$include_reposts = 1; // int | If set to 1 (the default), posts that are reposts will be included. If set to 0, reposts will be excluded. See the repost_count field of post objects for details about how reposts are identified.

try {
    $result = $apiInstance->searchUserPosts($user_id, $search, $types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling UsersApi->searchUserPosts: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **user_id** | **string**| The user ID of the user whose posts will be retrieved. Using &#39;me&#39; as the user_id will return the posts for the current user. | |
| **search** | **string**| The search query used to find posts. | |
| **types** | **string**| A comma separated list of the post types to return.  The available post types are: offer, wanted, admin | |
| **sources** | **string**| A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or all the open archive groups the requested user has posted to if latitude, longitude and radius aren&#39;t passed). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required. | |
| **sort_by** | **string**| How to sort the posts that are returned.  One of: relevance, date, active, distance &lt;br /&gt;&lt;br /&gt; Relevance sorting will sort the posts that best match the search query first. Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first. | [optional] [default to &#39;relevance&#39;] |
| **group_ids** | **string**| A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the &#39;groups&#39; source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, this field is required if the &#39;groups&#39; source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br/&gt; *To determine which group IDs were used and which were discarded, use the group_ids field in the response. | [optional] [default to &#39;The group IDs of every group the current user is a member of.&#39;] |
| **per_page** | **int**| The number of posts to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 100). | [optional] [default to 20] |
| **page** | **int**| The page of posts to return. | [optional] [default to 1] |
| **device_pixel_ratio** | **float**| Client device pixel ratio used to determine thumbnail size (default 1.0). | [optional] [default to 1.0] |
| **latitude** | **float**| The latitude of a point around which to return posts. | [optional] |
| **longitude** | **float**| The longitude of a point around which to return posts. | [optional] |
| **radius** | **float**| The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned. | [optional] |
| **date_min** | **\DateTime**| Only posts newer than or equal to this UTC date and time will be returned. | [optional] |
| **date_max** | **\DateTime**| Only posts older than this UTC date and time will be returned. | [optional] |
| **outcomes** | **string**| A comma separated list of the post outcomes to return.  The available post outcomes are: satisfied, withdrawn &lt;br /&gt;&lt;br /&gt; There are also a couple special values that can be passed.  If set to an empty string (the default), only posts that are not satisfied and not withdrawn and not expired are returned. If set to &#39;all&#39;, all posts will be returned no matter what outcome the posts have. If set to &#39;not-promised&#39;, only posts that are not satisfied ant not withdrawn and not expired and not promised are returned. | [optional] |
| **include_reposts** | **int**| If set to 1 (the default), posts that are reposts will be included. If set to 0, reposts will be excluded. See the repost_count field of post objects for details about how reposts are identified. | [optional] [default to 1] |

### Return type

[**\OpenAPI\Client\Model\SearchUserPosts200Response**](../Model/SearchUserPosts200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)
