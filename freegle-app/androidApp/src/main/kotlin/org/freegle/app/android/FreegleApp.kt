package org.freegle.app.android

import android.app.Application
import org.freegle.app.android.data.FreeglePreferences
import org.freegle.app.di.sharedModule
import org.koin.android.ext.koin.androidContext
import org.koin.core.context.startKoin
import org.koin.dsl.module

class FreegleApp : Application() {
    override fun onCreate() {
        super.onCreate()
        startKoin {
            androidContext(this@FreegleApp)
            modules(
                sharedModule(BuildConfig.API_BASE_URL, BuildConfig.API_V1_BASE_URL),
                module {
                    single { FreeglePreferences(get()) }
                },
            )
        }
    }
}
