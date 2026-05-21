# OpenAPI\Client\GroupsApi

Search, subscribe and unsubscribe to groups.

All URIs are relative to https://trashnothing.com/api/v1.4, except if the operation defines another base path.

| Method | HTTP request | Description |
| ------------- | ------------- | ------------- |
| [**getGroup()**](GroupsApi.md#getGroup) | **GET** /groups/{group_id} | Retrieve a group |
| [**getGroupsByIds()**](GroupsApi.md#getGroupsByIds) | **GET** /groups/multiple | Retrieve multiple groups |
| [**searchGroups()**](GroupsApi.md#searchGroups) | **GET** /groups | Search groups |


## `getGroup()`

```php
getGroup($group_id): \OpenAPI\Client\Model\Group
```

Retrieve a group

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\GroupsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$group_id = 'group_id_example'; // string | The ID of the group to retrieve.

try {
    $result = $apiInstance->getGroup($group_id);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling GroupsApi->getGroup: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **group_id** | **string**| The ID of the group to retrieve. | |

### Return type

[**\OpenAPI\Client\Model\Group**](../Model/Group.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `getGroupsByIds()`

```php
getGroupsByIds($group_ids, $latitude, $longitude): \OpenAPI\Client\Model\Group[]
```

Retrieve multiple groups

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\GroupsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$group_ids = 'group_ids_example'; // string | The IDs of the groups to retrieve.  If more than 20 group IDs are passed, only the first 20 groups will be returned.
$latitude = 3.4; // float | If latitude and longitude are passed, each group returned will have a supported_point boolean property indicating whether the group supports the point.
$longitude = 3.4; // float | If latitude and longitude are passed, each group returned will have a supported_point boolean property indicating whether the group supports the point.

try {
    $result = $apiInstance->getGroupsByIds($group_ids, $latitude, $longitude);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling GroupsApi->getGroupsByIds: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **group_ids** | **string**| The IDs of the groups to retrieve.  If more than 20 group IDs are passed, only the first 20 groups will be returned. | |
| **latitude** | **float**| If latitude and longitude are passed, each group returned will have a supported_point boolean property indicating whether the group supports the point. | [optional] |
| **longitude** | **float**| If latitude and longitude are passed, each group returned will have a supported_point boolean property indicating whether the group supports the point. | [optional] |

### Return type

[**\OpenAPI\Client\Model\Group[]**](../Model/Group.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)

## `searchGroups()`

```php
searchGroups($name, $latitude, $longitude, $distance, $country, $region, $postal_code, $page, $per_page): \OpenAPI\Client\Model\SearchGroups200Response
```

Search groups

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');


// Configure API key authorization: api_key
$config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKey('api_key', 'YOUR_API_KEY');
// Uncomment below to setup prefix (e.g. Bearer) for API key, if needed
// $config = OpenAPI\Client\Configuration::getDefaultConfiguration()->setApiKeyPrefix('api_key', 'Bearer');


$apiInstance = new OpenAPI\Client\Api\GroupsApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client(),
    $config
);
$name = 'name_example'; // string | Find groups that have the given text somewhere in their name (case insensitive).
$latitude = 3.4; // float | Find groups near the given latitude and longitude.
$longitude = 3.4; // float | Find groups near the given latitude and longitude.
$distance = 100.0; // float | When latitude and longitude are passed, distance can optionally be passed to only return groups within a certain distance (in kilometers) from the point specified by the latitude and longitude.  The distance must be > 0 and <= 150 and will default to 100.
$country = 'country_example'; // string | Find groups in the given country where country is a 2 letter country code for the country (see https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2 ).
$region = 'region_example'; // string | For countries with regions (AU, CA, GB, US), search groups in a specific region as specified by the region abbreviation.  The supported regions and their abbreviations are listed below. <br /><br /> NOTE: The region and postal_code parameters cannot be used at the same time and if both are passed then the postal_code will take priority. <br /><br /> --- <br /><br /> **AU**<br /> - QLD: Queensland<br /> - SA: South Australia<br /> - TAS: Tasmania<br /> - VIC: Victoria<br /> - WA: Western Australia<br /> - NT: Northern Territory<br /> - NSW: New South Wales - ACT<br /> <br /> **CA**<br /> - AB: Alberta<br /> - BC: British Columbia<br /> - MB: Manitoba<br /> - NB: New Brunswick<br /> - NL: Newfoundland and Labrador<br /> - NS: Nova Scotia<br /> - ON: Ontario<br /> - QC: Quebec<br /> - SK: Saskatchewan<br /> - PE: Prince Edward Island<br /> <br /> **GB**<br /> - E: East<br /> - EM: East Midlands<br /> - LDN: London<br /> - NE: North East<br /> - NW: North West<br /> - NI: Northern Ireland<br /> - SC: Scotland<br /> - SE: South East<br /> - SW: South West<br /> - WA: Wales<br /> - WM: West Midlands<br /> - YH: Yorkshire and the Humber<br /> <br /> **US**<br /> All 50 states and the District of Columbia are supported.  For the abbreviations, see: https://github.com/jasonong/List-of-US-States/blob/master/states.csv
$postal_code = 'postal_code_example'; // string | Find groups in the given postal code.  Only a few countries support postal code searches (US, CA, AU, GB).  The country parameter must be passed when the postal_code parameter is set. <br /><br /> NOTE: The region and postal_code parameters cannot be used at the same time and if both are passed then the postal_code will take priority.
$page = 1; // int | The page of groups to return.
$per_page = 20; // int | The number of groups to return per page (must be >= 1 and <= 100).

try {
    $result = $apiInstance->searchGroups($name, $latitude, $longitude, $distance, $country, $region, $postal_code, $page, $per_page);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling GroupsApi->searchGroups: ', $e->getMessage(), PHP_EOL;
}
```

### Parameters

| Name | Type | Description  | Notes |
| ------------- | ------------- | ------------- | ------------- |
| **name** | **string**| Find groups that have the given text somewhere in their name (case insensitive). | [optional] |
| **latitude** | **float**| Find groups near the given latitude and longitude. | [optional] |
| **longitude** | **float**| Find groups near the given latitude and longitude. | [optional] |
| **distance** | **float**| When latitude and longitude are passed, distance can optionally be passed to only return groups within a certain distance (in kilometers) from the point specified by the latitude and longitude.  The distance must be &gt; 0 and &lt;&#x3D; 150 and will default to 100. | [optional] [default to 100.0] |
| **country** | **string**| Find groups in the given country where country is a 2 letter country code for the country (see https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2 ). | [optional] |
| **region** | **string**| For countries with regions (AU, CA, GB, US), search groups in a specific region as specified by the region abbreviation.  The supported regions and their abbreviations are listed below. &lt;br /&gt;&lt;br /&gt; NOTE: The region and postal_code parameters cannot be used at the same time and if both are passed then the postal_code will take priority. &lt;br /&gt;&lt;br /&gt; --- &lt;br /&gt;&lt;br /&gt; **AU**&lt;br /&gt; - QLD: Queensland&lt;br /&gt; - SA: South Australia&lt;br /&gt; - TAS: Tasmania&lt;br /&gt; - VIC: Victoria&lt;br /&gt; - WA: Western Australia&lt;br /&gt; - NT: Northern Territory&lt;br /&gt; - NSW: New South Wales - ACT&lt;br /&gt; &lt;br /&gt; **CA**&lt;br /&gt; - AB: Alberta&lt;br /&gt; - BC: British Columbia&lt;br /&gt; - MB: Manitoba&lt;br /&gt; - NB: New Brunswick&lt;br /&gt; - NL: Newfoundland and Labrador&lt;br /&gt; - NS: Nova Scotia&lt;br /&gt; - ON: Ontario&lt;br /&gt; - QC: Quebec&lt;br /&gt; - SK: Saskatchewan&lt;br /&gt; - PE: Prince Edward Island&lt;br /&gt; &lt;br /&gt; **GB**&lt;br /&gt; - E: East&lt;br /&gt; - EM: East Midlands&lt;br /&gt; - LDN: London&lt;br /&gt; - NE: North East&lt;br /&gt; - NW: North West&lt;br /&gt; - NI: Northern Ireland&lt;br /&gt; - SC: Scotland&lt;br /&gt; - SE: South East&lt;br /&gt; - SW: South West&lt;br /&gt; - WA: Wales&lt;br /&gt; - WM: West Midlands&lt;br /&gt; - YH: Yorkshire and the Humber&lt;br /&gt; &lt;br /&gt; **US**&lt;br /&gt; All 50 states and the District of Columbia are supported.  For the abbreviations, see: https://github.com/jasonong/List-of-US-States/blob/master/states.csv | [optional] |
| **postal_code** | **string**| Find groups in the given postal code.  Only a few countries support postal code searches (US, CA, AU, GB).  The country parameter must be passed when the postal_code parameter is set. &lt;br /&gt;&lt;br /&gt; NOTE: The region and postal_code parameters cannot be used at the same time and if both are passed then the postal_code will take priority. | [optional] |
| **page** | **int**| The page of groups to return. | [optional] [default to 1] |
| **per_page** | **int**| The number of groups to return per page (must be &gt;&#x3D; 1 and &lt;&#x3D; 100). | [optional] [default to 20] |

### Return type

[**\OpenAPI\Client\Model\SearchGroups200Response**](../Model/SearchGroups200Response.md)

### Authorization

[api_key](../../README.md#api_key)

### HTTP request headers

- **Content-Type**: Not defined
- **Accept**: `application/json`

[[Back to top]](#) [[Back to API list]](../../README.md#endpoints)
[[Back to Model list]](../../README.md#models)
[[Back to README]](../../README.md)
