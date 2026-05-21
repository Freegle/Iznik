# GetPostsByIds200Response

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**posts** | [**\OpenAPI\Client\Model\Post[]**](Post.md) |  | [optional]
**not_found** | **string[]** | The IDs of posts that weren&#39;t found (may have been deleted or never existed). | [optional]
**forbidden** | **string[]** | The IDs of posts that are forbidden for the current user. | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
