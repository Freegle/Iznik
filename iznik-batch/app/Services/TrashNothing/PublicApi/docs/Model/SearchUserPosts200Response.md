# SearchUserPosts200Response

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**posts** | [**\OpenAPI\Client\Model\PostSearchResult[]**](PostSearchResult.md) |  | [optional]
**num_posts** | **int** | The total number of posts available. | [optional]
**page** | **int** | The page number of the posts being returned. | [optional]
**per_page** | **int** | The number of posts being returned per page. | [optional]
**num_pages** | **int** | The total number of pages available. | [optional]
**start_index** | **int** | The index of the first post being returned (an integer between 1 and num_posts). | [optional]
**end_index** | **int** | The index of the last post being returned (an integer between start_index and num_posts). | [optional]
**group_ids** | **string[]** | The IDs of the groups that the posts were retrieved from (will be null when no group IDs were used). These IDs may be a subset of the requested group IDs when a request includes group IDs for groups that are not open archives and that the current user is not a member of.  If the open_archive_groups source is used, these IDs may include the IDs of open archive groups that weren&#39;t present in the group_ids parameter of the request. | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
