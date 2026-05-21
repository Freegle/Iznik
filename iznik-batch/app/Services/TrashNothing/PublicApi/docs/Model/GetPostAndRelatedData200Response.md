# GetPostAndRelatedData200Response

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**post** | [**\OpenAPI\Client\Model\Post**](Post.md) |  | [optional]
**author** | [**\OpenAPI\Client\Model\User**](User.md) |  | [optional]
**author_posts** | [**\OpenAPI\Client\Model\Post[]**](Post.md) | Other active posts from the post author in the last 90 days. A maximum of 30 posts will be returned. | [optional]
**author_offer_count** | **int** | Count of offer posts made by the post author in the last 90 days. | [optional]
**author_wanted_count** | **int** | Count of wanted posts made by the post author in the last 90 days. | [optional]
**groups** | [**\OpenAPI\Client\Model\Group[]**](Group.md) | The groups the post is published on. | [optional]
**user_can_reply** | **bool** | Whether or not the current user (if any) can reply to this post. Unverified users cannot reply to posts until they verify their account. | [optional]
**viewed** | **bool** | Whether or not the current user has previously viewed this post.  Will be null for api key requests and for the current users&#39; posts. | [optional]
**replied** | **bool** | Whether or not the current user has replied to this post.  Will be null for api key requests and for the current users&#39; posts. | [optional]
**bookmarked** | **bool** | Whether or not the current user has bookmarked this post.  Will be null for api key requests and for the current users&#39; posts. | [optional]
**hidden** | **bool** | Whether the current user has hidden the post author. | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
