package org.freegle.app.di

import org.freegle.app.api.AuthManager
import org.freegle.app.api.FreegleApi
import org.freegle.app.repository.ChatRepository
import org.freegle.app.repository.MessageRepository
import org.freegle.app.repository.NotificationRepository
import org.freegle.app.repository.UserRepository
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.module

fun sharedModule(baseUrl: String, v1BaseUrl: String) = module {
    single { AuthManager() }
    single { FreegleApi(baseUrl, v1BaseUrl, get()) }
    singleOf(::MessageRepository)
    singleOf(::ChatRepository)
    singleOf(::NotificationRepository)
    single { UserRepository(get(), get()) }
}
