# GetAllPostsChanges200ResponseChangesInner

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**change_id** | **int** | The unique ID for this change.  This is an auto-incrementing ID that can be used by clients to make sure they have received every change.  If a client detects a gap in the change ID (eg. 1, 3, 4 instead of 1,2,3,4) then the client should repoll older changes to get the missing change. | [optional]
**post_id** | **string** |  | [optional]
**date** | **\DateTime** | The UTC date and time when the post was changed. | [optional]
**type** | **string** | The type of change.  One of: published, deleted, undeleted, satisfied, promised, unpromised, withdrawn, edited, expired | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
