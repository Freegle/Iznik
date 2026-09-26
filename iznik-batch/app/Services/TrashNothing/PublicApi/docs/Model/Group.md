# Group

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**group_id** | **string** |  | [optional]
**name** | **string** | The name of the group (not guaranteed to be unique). | [optional]
**identifier** | **string** | A unique identifier for the group that is used in URLs. | [optional]
**homepage** | **string** | A URL to the group homepage. | [optional]
**member_count** | **int** | The number of members who belong to the group. | [optional]
**latitude** | **float** |  | [optional]
**longitude** | **float** |  | [optional]
**timezone** | **string** | The timezone that the group is in (eg. America/New_York). | [optional]
**open_membership** | **bool** | When true, the group allows anyone to join.  When false, the group moderators review and approve applicants. | [optional]
**open_archives** | **bool** | When true, the group posts are viewable by anyone.  When false, the group posts can only be viewed by members of the group. | [optional]
**has_questions** | **bool** | When true, anyone requesting membership to this group will be required to answer a new membership questionnaire. | [optional]
**country** | [**\OpenAPI\Client\Model\GroupCountry**](GroupCountry.md) |  | [optional]
**region** | [**\OpenAPI\Client\Model\GroupRegion**](GroupRegion.md) |  | [optional]
**membership** | [**\OpenAPI\Client\Model\GroupMembership**](GroupMembership.md) |  | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
