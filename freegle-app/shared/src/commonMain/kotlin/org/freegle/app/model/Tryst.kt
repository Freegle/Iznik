package org.freegle.app.model

import kotlinx.serialization.Serializable

@Serializable
data class Tryst(
    val id: Long = 0,
    val user1: Long = 0,
    val user2: Long = 0,
    val arrangedat: String? = null,
    val arrangedfor: String? = null,
)
