# OpenAPIClient-php

This is the REST API for [trashnothing.com](https://trashnothing.com).

To learn more about the API or to register your app for use with the API
visit the [Trash Nothing Developer page](https://trashnothing.com/app/developer).

NOTE: All date-time values are [UTC](https://en.wikipedia.org/wiki/Coordinated_Universal_Time)
and are in [ISO 8601 format](https://en.wikipedia.org/wiki/ISO_8601) (eg. 2019-02-03T01:23:53).



## Installation & Usage

### Requirements

PHP 8.1 and later.

### Composer

To install the bindings via [Composer](https://getcomposer.org/), add the following to `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/GIT_USER_ID/GIT_REPO_ID.git"
    }
  ],
  "require": {
    "GIT_USER_ID/GIT_REPO_ID": "*@dev"
  }
}
```

Then run `composer install`

### Manual Installation

Download the files and include `autoload.php`:

```php
<?php
require_once('/path/to/OpenAPIClient-php/vendor/autoload.php');
```

## Getting Started

Please follow the [installation procedure](#installation--usage) and then run the following:

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

## API Endpoints

All URIs are relative to *https://trashnothing.com/api/v1.4*

Class | Method | HTTP request | Description
------------ | ------------- | ------------- | -------------
*GroupsApi* | [**getGroup**](docs/Api/GroupsApi.md#getgroup) | **GET** /groups/{group_id} | Retrieve a group
*GroupsApi* | [**getGroupsByIds**](docs/Api/GroupsApi.md#getgroupsbyids) | **GET** /groups/multiple | Retrieve multiple groups
*GroupsApi* | [**searchGroups**](docs/Api/GroupsApi.md#searchgroups) | **GET** /groups | Search groups
*PostsApi* | [**getAllPosts**](docs/Api/PostsApi.md#getallposts) | **GET** /posts/all | List all posts
*PostsApi* | [**getAllPostsChanges**](docs/Api/PostsApi.md#getallpostschanges) | **GET** /posts/all/changes | List all post changes
*PostsApi* | [**getPost**](docs/Api/PostsApi.md#getpost) | **GET** /posts/{post_id} | Retrieve a post
*PostsApi* | [**getPostAndRelatedData**](docs/Api/PostsApi.md#getpostandrelateddata) | **GET** /posts/{post_id}/display | Retrieve post display data
*PostsApi* | [**getPosts**](docs/Api/PostsApi.md#getposts) | **GET** /posts | List posts
*PostsApi* | [**getPostsByIds**](docs/Api/PostsApi.md#getpostsbyids) | **GET** /posts/multiple | Retrieve multiple posts
*PostsApi* | [**searchPosts**](docs/Api/PostsApi.md#searchposts) | **GET** /posts/search | Search posts
*UsersApi* | [**getUserPosts**](docs/Api/UsersApi.md#getuserposts) | **GET** /users/{user_id}/posts | List posts by a user
*UsersApi* | [**searchUserPosts**](docs/Api/UsersApi.md#searchuserposts) | **GET** /users/{user_id}/posts/search | Search posts by a user

## Models

- [Feedback](docs/Model/Feedback.md)
- [GetAllPosts200Response](docs/Model/GetAllPosts200Response.md)
- [GetAllPostsChanges200Response](docs/Model/GetAllPostsChanges200Response.md)
- [GetAllPostsChanges200ResponseChangesInner](docs/Model/GetAllPostsChanges200ResponseChangesInner.md)
- [GetPostAndRelatedData200Response](docs/Model/GetPostAndRelatedData200Response.md)
- [GetPostsByIds200Response](docs/Model/GetPostsByIds200Response.md)
- [GetUserPosts200Response](docs/Model/GetUserPosts200Response.md)
- [Group](docs/Model/Group.md)
- [GroupCountry](docs/Model/GroupCountry.md)
- [GroupMembership](docs/Model/GroupMembership.md)
- [GroupMembershipQuestionnaire](docs/Model/GroupMembershipQuestionnaire.md)
- [GroupRegion](docs/Model/GroupRegion.md)
- [Photo](docs/Model/Photo.md)
- [PhotoImagesInner](docs/Model/PhotoImagesInner.md)
- [Post](docs/Model/Post.md)
- [PostSearchResult](docs/Model/PostSearchResult.md)
- [SearchGroups200Response](docs/Model/SearchGroups200Response.md)
- [SearchUserPosts200Response](docs/Model/SearchUserPosts200Response.md)
- [User](docs/Model/User.md)
- [UserFeedback](docs/Model/UserFeedback.md)

## Authorization

Authentication schemes defined for the API:
### api_key

- **Type**: API key
- **API key parameter name**: api_key
- **Location**: URL query string


## Tests

To run the tests, use:

```bash
composer install
vendor/bin/phpunit
```

## Author



## About this package

This PHP package is automatically generated by the [OpenAPI Generator](https://openapi-generator.tech) project:

- API version: `1.4`
    - Generator version: `7.23.0-SNAPSHOT`
- Build package: `org.openapitools.codegen.languages.PhpClientCodegen`
