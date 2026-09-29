# Photo

## Properties

Name | Type | Description | Notes
------------ | ------------- | ------------- | -------------
**photo_id** | **string** |  | [optional]
**thumbnail** | **string** | A URL to a thumbnail of this photo.  The size of the thumbnail depends on the device_pixel_ratio parameter and it is not guaranteed to be square. | [optional]
**url** | **string** | A URL to a large version of this photo (but not necessarily the largest size available). | [optional]
**blurhash** | **string** | A blurhash of the photo that can be used as a placeholder while the photo is loading (see: https://github.com/woltapp/blurhash). May be null if no blurhash is available and the length of the blurhash can vary based on the photo. | [optional]
**avif** | **bool** | Whether avif versions of the image are available.  If they are, the avif image can be loaded by changing the extension on any of the image URLs to &#39;avif&#39;. | [optional]
**images** | [**\OpenAPI\Client\Model\PhotoImagesInner[]**](PhotoImagesInner.md) | All the versions of this photo ordered from smallest to largest.  This list is guaranteed to include the photos specified by the above thumbnail and url properties. | [optional]

[[Back to Model list]](../../README.md#models) [[Back to API list]](../../README.md#endpoints) [[Back to README]](../../README.md)
