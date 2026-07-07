package org.freegle.app

interface Platform {
    val name: String
}

expect fun getPlatform(): Platform
