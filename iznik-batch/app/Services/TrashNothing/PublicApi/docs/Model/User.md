# User

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**user_id** | **string** |  | [optional]
**username** | **string** | A username that can be displayed for the user (the username is NOT guaranteed to be unique). Will be null for api key requests and requests where the oauth user doesn&#39;t belong to any of the same groups as this user. | [optional]
**country** | **string** | A 2 letter country code for the country that has been automatically detected for the user (see https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2 ). May be null if no country has been set. | [optional]
**profile_image** | **string** | A URL to a profile image for the user.  Profile images sizes vary based on the source (Google, Facebook, Gravatar, etc) and some can be as small as 64px by 64px.  Will be null for api key requests and requests where the oauth user doesn&#39;t belong to any of the same groups as this user. | [optional]
**member_since** | **string** | The date and time when the user first became publicly active on a group (the date may be older than when the user signed up). | [optional]
**firstname** | **string** | The first name of the user (may be null). | [optional]
**lastname** | **string** | The last name of the user (may be null). | [optional]
**reply_time** | **int** | An estimate of how many seconds it takes this user to reply to messages. May be null when there is not enough data to calculate an estimate. | [optional]
**feedback** | [**\OpenAPI\Client\Model\UserFeedback**](UserFeedback.md) |  | [optional]
**about_me** | **string** | A short bio a user has written about themselves to help other members get to know them better. May be null if the user has not written anything about themselves. | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
