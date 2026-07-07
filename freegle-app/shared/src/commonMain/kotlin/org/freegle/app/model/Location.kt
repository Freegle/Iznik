package org.freegle.app.model

import kotlinx.serialization.Serializable

@Serializable
data class LocationResult(
    val id: Long = 0,
    val name: String? = null,
    val type: String? = null,
    val lat: Double = 0.0,
    val lng: Double = 0.0,
    val areaid: Long? = null,
    val areaname: String? = null,
    val groupid: Long? = null,
    val groupname: String? = null,
    val area: LocationArea? = null,
    val groupsnear: List<GroupNear>? = null,
)

@Serializable
data class LocationArea(
    val id: Long = 0,
    val name: String? = null,
    val lat: Double = 0.0,
    val lng: Double = 0.0,
)

@Serializable
data class GroupNear(
    val id: Long = 0,
    val nameshort: String? = null,
    val namedisplay: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val dist: Double? = null,
)

// V2 location/latlng returns a single LocationResult
@Serializable
data class LocationResponse(
    val locations: List<LocationResult> = emptyList(),
    val location: LocationResult? = null,
)

@Serializable
data class GroupInfo(
    val id: Long = 0,
    val nameshort: String? = null,
    val namedisplay: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    val region: String? = null,
)
