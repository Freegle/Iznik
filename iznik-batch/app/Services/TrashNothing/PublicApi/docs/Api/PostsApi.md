# OpenAPI\Client\PostsApi

Retrieve and update posts.

All URIs are relative to https://trashnothing.com/api/v1.4, except if the operation defines another base path.

| Method | HTTP request | Description |
| ------------- | ------------- | ------------- |
| [**getAllPosts()**](PostsApi.md#getAllPosts) | **GET** /posts/all | List all posts |
| [**getAllPostsChanges()**](PostsApi.md#getAllPostsChanges) | **GET** /posts/all/changes | List all post changes |
| [**getPost()**](PostsApi.md#getPost) | **GET** /posts/{post_id} | Retrieve a post |
| [**getPostAndRelatedData()**](PostsApi.md#getPostAndRelatedData) | **GET** /posts/{post_id}/display | Retrieve post display data |
| [**getPosts()**](PostsApi.md#getPosts) | **GET** /posts | List posts |
| [**getPostsByIds()**](PostsApi.md#getPostsByIds) | **GET** /posts/multiple | Retrieve multiple posts |
| [**searchPosts()**](PostsApi.md#searchPosts) | **GET** /posts/search | Search posts |


## `getAllPosts()`

```php
getAllPosts($types, $date_min, $date_max, $per_page, $page, $device_pixel_ratio): \OpenAPI\Client\Model\GetAllPosts200Response
```

List all posts

This endpoint provides an easy way to get a feed of all the publicly published posts on Trash Nothing. It provides access to all publicly published offer and wanted posts from the last 30 days. The posts are sorted by date (newest first). <br /><br /> There are fewer options for filtering, sorting and searching posts with this endpoint but there is no 1,000 post limit and posts that are crossposted to multiple groups are not merged together in the response.  In most cases, crossposted posts are easy to detect because they have the same user_id, title and content.

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$types = 'types_example'; // string | A comma separated list of the post types to return.  The available post types are: offer, wanted
$date_min = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts newer than or equal to this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_max. And the date and time must be within the last 30 days. And the date and time must be rounded to the nearest second.
$date_max = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts older than this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_min. And the date and time must be rounded to the nearest second.
$per_page = 20; // int | The number of posts to return per page (must be >= 1 and <= 50).
$page = 1; // int | The page of posts to return.
$device_pixel_ratio = 1.0; // float | Client device pixel ratio used to determine thumbnail size (default 1.0).

try {
    $result = $apiInstance->getAllPosts($types, $date_min, $date_max, $per_page, $page, $device_pixel_ratio);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->getAllPosts: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **types** | **string**| A comma separated list of the post types to return.  The available post types are: offer, wanted | |
| **date_min** | **\DateTime**| Only posts newer than or equal to this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_max. And the date and time must be within the last 30 days. And the date and time must be rounded to the nearest second. | |
| **date_max** | **\DateTime**| Only posts older than this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_min. And the date and time must be rounded to the nearest second. | |
| **per_page** | **int**| The number of posts to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 50). | [optional] [default to 20] |
| **page** | **int**| The page of posts to return. | [optional] [default to 1] |
| **device_pixel_ratio** | **float**| Client device pixel ratio used to determine thumbnail size (default 1.0). | [optional] [default to 1.0] |

### Return type

[**\OpenAPI\Client\Model\GetAllPosts200Response**](../Model/GetAllPosts200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `getAllPostsChanges()`

```php
getAllPostsChanges($date_min, $date_max, $per_page, $page): \OpenAPI\Client\Model\GetAllPostsChanges200Response
```

List all post changes

This endpoint provides an easy way to get a feed of all the changes that have been made to publicly published posts on Trash Nothing.  Similar to the /posts/all endpoint, only data from the last 30 days is available and the changes are sorted by date (newest first).  Every change includes the date of the change, the post_id of the post that was changed and the type of change. <br /><br /> The different types of changes that are returned are listed below. <br /><br /> - published<br /> - deleted<br /> - undeleted<br /> - satisfied<br /> - promised<br /> - unpromised<br /> - withdrawn<br /> - edited<br /> <br /> For published and edited changes, clients can use the retrieve post API endpoint to get the edits that have been made to the post.

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$date_min = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only changes newer than or equal to this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_max. And the date and time must be within the last 30 days. And the date and time must be rounded to the nearest second.
$date_max = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only changes older than this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_min. And the date and time must be rounded to the nearest second.
$per_page = 20; // int | The number of changes to return per page (must be >= 1 and <= 50).
$page = 1; // int | The page of changes to return.

try {
    $result = $apiInstance->getAllPostsChanges($date_min, $date_max, $per_page, $page);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->getAllPostsChanges: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **date_min** | **\DateTime**| Only changes newer than or equal to this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_max. And the date and time must be within the last 30 days. And the date and time must be rounded to the nearest second. | |
| **date_max** | **\DateTime**| Only changes older than this UTC date and time will be returned. The UTC date and time used must be within a day or less of date_min. And the date and time must be rounded to the nearest second. | |
| **per_page** | **int**| The number of changes to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 50). | [optional] [default to 20] |
| **page** | **int**| The page of changes to return. | [optional] [default to 1] |

### Return type

[**\OpenAPI\Client\Model\GetAllPostsChanges200Response**](../Model/GetAllPostsChanges200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `getPost()`

```php
getPost($post_id): \OpenAPI\Client\Model\Post
```

Retrieve a post

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$post_id = 'post_id_example'; // string | The ID of the post to retrieve.

try {
    $result = $apiInstance->getPost($post_id);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->getPost: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **post_id** | **string**| The ID of the post to retrieve. | |

### Return type

[**\OpenAPI\Client\Model\Post**](../Model/Post.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `getPostAndRelatedData()`

```php
getPostAndRelatedData($post_id): \OpenAPI\Client\Model\GetPostAndRelatedData200Response
```

Retrieve post display data

Retrieve a post and other data related to the post that is useful for displaying the post such as data about the user who posted the post and the groups the post was posted on.

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$post_id = 'post_id_example'; // string | The ID of the post to retrieve.

try {
    $result = $apiInstance->getPostAndRelatedData($post_id);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->getPostAndRelatedData: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **post_id** | **string**| The ID of the post to retrieve. | |

### Return type

[**\OpenAPI\Client\Model\GetPostAndRelatedData200Response**](../Model/GetPostAndRelatedData200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `getPosts()`

```php
getPosts($types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts): \OpenAPI\Client\Model\GetUserPosts200Response
```

List posts

NOTE: When paging through the posts returned by this endpoint, there will be at most 1,000 posts that can be returned (eg. 50 pages worth of posts with the default per_page value of 20).  In areas where there are more than 1,000 posts, clients can use more specific query parameters to adjust which posts are returned. NOTE: Passing the latitude, longitude and radius parameters filters all posts by their location and so these parameters will temporarily override the current users' location preferences. When latitude, longitude and radius are not specified, public posts will be filtered by the current users' location preferences.

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$types = 'types_example'; // string | A comma separated list of the post types to return.  The available post types are: offer, wanted, admin
$sources = 'sources_example'; // string | A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or the current users' location if latitude, longitude and radius aren't passed). <br /><br /> NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required.
$sort_by = 'date'; // string | How to sort the posts that are returned.  One of: date, active, distance <br /><br /> Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first.
$group_ids = 'The group IDs of every group the current user is a member of.'; // string | A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the 'groups' source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). <br /><br /> NOTE: For requests using an api key instead of oauth, this field is required if the 'groups' source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). <br /><br/> *To determine which group IDs were used and which were discarded, use the group_ids field in the response.
$per_page = 20; // int | The number of posts to return per page (must be >= 1 and <= 100).
$page = 1; // int | The page of posts to return.
$device_pixel_ratio = 1.0; // float | Client device pixel ratio used to determine thumbnail size (default 1.0).
$latitude = 3.4; // float | The latitude of a point around which to return posts.
$longitude = 3.4; // float | The longitude of a point around which to return posts.
$radius = 3.4; // float | The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned.
$date_min = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts newer than or equal to this UTC date and time will be returned.  If unset, defaults to the current date and time minus 90 days.
$date_max = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts older than this UTC date and time will be returned.  If unset, defaults to the current date and time.
$outcomes = 'outcomes_example'; // string | A comma separated list of the post outcomes to return.  The available post outcomes are: satisfied, withdrawn <br /><br /> There are also a couple special values that can be passed.  If set to an empty string (the default), only posts that are not satisfied and not withdrawn and not expired are returned. If set to 'all', all posts will be returned no matter what outcome the posts have. If set to 'not-promised', only posts that are not satisfied ant not withdrawn and not expired and not promised are returned.
$include_reposts = 1; // int | If set to 1 (the default), posts that are reposts will be included. If set to 0, reposts will be excluded. See the repost_count field of post objects for details about how reposts are identified.

try {
    $result = $apiInstance->getPosts($types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->getPosts: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **types** | **string**| A comma separated list of the post types to return.  The available post types are: offer, wanted, admin | |
| **sources** | **string**| A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or the current users&#39; location if latitude, longitude and radius aren&#39;t passed). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required. | |
| **sort_by** | **string**| How to sort the posts that are returned.  One of: date, active, distance &lt;br /&gt;&lt;br /&gt; Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first. | [optional] [default to &#39;date&#39;] |
| **group_ids** | **string**| A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the &#39;groups&#39; source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, this field is required if the &#39;groups&#39; source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br/&gt; *To determine which group IDs were used and which were discarded, use the group_ids field in the response. | [optional] [default to &#39;The group IDs of every group the current user is a member of.&#39;] |
| **per_page** | **int**| The number of posts to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 100). | [optional] [default to 20] |
| **page** | **int**| The page of posts to return. | [optional] [default to 1] |
| **device_pixel_ratio** | **float**| Client device pixel ratio used to determine thumbnail size (default 1.0). | [optional] [default to 1.0] |
| **latitude** | **float**| The latitude of a point around which to return posts. | [optional] |
| **longitude** | **float**| The longitude of a point around which to return posts. | [optional] |
| **radius** | **float**| The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned. | [optional] |
| **date_min** | **\DateTime**| Only posts newer than or equal to this UTC date and time will be returned.  If unset, defaults to the current date and time minus 90 days. | [optional] |
| **date_max** | **\DateTime**| Only posts older than this UTC date and time will be returned.  If unset, defaults to the current date and time. | [optional] |
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

## `getPostsByIds()`

```php
getPostsByIds($post_ids): \OpenAPI\Client\Model\GetPostsByIds200Response
```

Retrieve multiple posts

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$post_ids = 'post_ids_example'; // string | A comma separated list of the post IDs. If more than 10 post IDs are passed, only the first 10 posts will be returned.

try {
    $result = $apiInstance->getPostsByIds($post_ids);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->getPostsByIds: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **post_ids** | **string**| A comma separated list of the post IDs. If more than 10 post IDs are passed, only the first 10 posts will be returned. | |

### Return type

[**\OpenAPI\Client\Model\GetPostsByIds200Response**](../Model/GetPostsByIds200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `searchPosts()`

```php
searchPosts($search, $types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts): \OpenAPI\Client\Model\SearchUserPosts200Response
```

Search posts

Searching posts takes the same arguments as listing posts except for the addition of the search and sort_by parameters. NOTE: When paging through the posts returned by this endpoint, there will be at most 1,000 posts that can be returned (eg. 50 pages worth of posts with the default per_page value of 20).  In areas where there are more than 1,000 posts, clients can use more specific query parameters to adjust which posts are returned.

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\PostsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$search = 'search_example'; // string | The search query used to find posts.
$types = 'types_example'; // string | A comma separated list of the post types to return.  The available post types are: offer, wanted, admin
$sources = 'sources_example'; // string | A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or the current users' location if latitude, longitude and radius aren't passed). <br /><br /> NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required.
$sort_by = 'relevance'; // string | How to sort the posts that are returned.  One of: relevance, date, active, distance <br /><br /> Relevance sorting will sort the posts that best match the search query first. Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first.
$group_ids = 'The group IDs of every group the current user is a member of.'; // string | A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the 'groups' source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). <br /><br /> NOTE: For requests using an api key instead of oauth, this field is required if the 'groups' source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). <br /><br/> *To determine which group IDs were used and which were discarded, use the group_ids field in the response.
$per_page = 20; // int | The number of posts to return per page (must be >= 1 and <= 100).
$page = 1; // int | The page of posts to return.
$device_pixel_ratio = 1.0; // float | Client device pixel ratio used to determine thumbnail size (default 1.0).
$latitude = 3.4; // float | The latitude of a point around which to return posts.
$longitude = 3.4; // float | The longitude of a point around which to return posts.
$radius = 3.4; // float | The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned.
$date_min = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts newer than or equal to this UTC date and time will be returned.  If unset, defaults to the current date and time minus 90 days.
$date_max = new \DateTime('2013-10-20T19:20:30+01:00'); // \DateTime | Only posts older than this UTC date and time will be returned.  If unset, defaults to the current date and time.
$outcomes = 'outcomes_example'; // string | A comma separated list of the post outcomes to return.  The available post outcomes are: satisfied, withdrawn <br /><br /> There are also a couple special values that can be passed.  If set to an empty string (the default), only posts that are not satisfied and not withdrawn and not expired are returned. If set to 'all', all posts will be returned no matter what outcome the posts have. If set to 'not-promised', only posts that are not satisfied ant not withdrawn and not expired and not promised are returned.
$include_reposts = 1; // int | If set to 1 (the default), posts that are reposts will be included. If set to 0, reposts will be excluded. See the repost_count field of post objects for details about how reposts are identified.

try {
    $result = $apiInstance->searchPosts($search, $types, $sources, $sort_by, $group_ids, $per_page, $page, $device_pixel_ratio, $latitude, $longitude, $radius, $date_min, $date_max, $outcomes, $include_reposts);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling PostsApi->searchPosts: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **search** | **string**| The search query used to find posts. | |
| **types** | **string**| A comma separated list of the post types to return.  The available post types are: offer, wanted, admin | |
| **sources** | **string**| A comma separated list of the post sources to retrieve posts from. The available sources are: groups, trashnothing, open_archive_groups. The trashnothing source is for public posts that are posted on Trash Nothing but are not associated with any group. The open_archive_groups source provides a way to easily request posts from groups that have open_archives set to true without having to pass a group_ids parameter.  When passed, it will automatically return posts from open archive groups that are within the area specified by the latitude, longitude and radius parameters (or the current users&#39; location if latitude, longitude and radius aren&#39;t passed). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, passing the trashnothing source or the open_archive_groups source makes the latitude, longitude and radius parameters required. | |
| **sort_by** | **string**| How to sort the posts that are returned.  One of: relevance, date, active, distance &lt;br /&gt;&lt;br /&gt; Relevance sorting will sort the posts that best match the search query first. Date sorting will sort posts from newest to oldest. Active sorting will sort active posts before satisfied, withdrawn and expired posts and then sort by date. Distance sorting will sort the closest posts first. | [optional] [default to &#39;relevance&#39;] |
| **group_ids** | **string**| A comma separated list of the group IDs to retrieve posts from. This parameter is only used if the &#39;groups&#39; source is passed in the sources parameter and only groups that the current user is a member of or that are open archives groups will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br /&gt; NOTE: For requests using an api key instead of oauth, this field is required if the &#39;groups&#39; source is passed. In addition, only posts from groups that have open_archives set to true will be used (the group IDs of other groups will be silently discarded*). &lt;br /&gt;&lt;br/&gt; *To determine which group IDs were used and which were discarded, use the group_ids field in the response. | [optional] [default to &#39;The group IDs of every group the current user is a member of.&#39;] |
| **per_page** | **int**| The number of posts to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 100). | [optional] [default to 20] |
| **page** | **int**| The page of posts to return. | [optional] [default to 1] |
| **device_pixel_ratio** | **float**| Client device pixel ratio used to determine thumbnail size (default 1.0). | [optional] [default to 1.0] |
| **latitude** | **float**| The latitude of a point around which to return posts. | [optional] |
| **longitude** | **float**| The longitude of a point around which to return posts. | [optional] |
| **radius** | **float**| The radius in meters of a circle centered at the point defined by the latitude and longitude parameters. When latitude, longitude and radius are passed, only posts within the circle defined by these parameters will be returned. | [optional] |
| **date_min** | **\DateTime**| Only posts newer than or equal to this UTC date and time will be returned.  If unset, defaults to the current date and time minus 90 days. | [optional] |
| **date_max** | **\DateTime**| Only posts older than this UTC date and time will be returned.  If unset, defaults to the current date and time. | [optional] |
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
