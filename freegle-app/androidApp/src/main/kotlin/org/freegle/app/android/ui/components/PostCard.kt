package org.freegle.app.android.ui.components

fun cleanTitle(subject: String): String {
    return subject
        .removePrefix("OFFER: ")
        .removePrefix("WANTED: ")
        .replace(Regex("\\s*\\(.*\\)\\s*$"), "") // Remove trailing (Location)
        .trim()
}

/** Extract the location from a subject like "OFFER: Sofa (Edinburgh EH1)" → "Edinburgh EH1" */
fun extractLocation(subject: String?): String? {
    if (subject == null) return null
    val match = Regex("\\(([^)]+)\\)\\s*$").find(subject)
    return match?.groupValues?.get(1)
}

// Parse ISO 8601 date string to approximate epoch millis (UTC)
private fun parseToMillis(dateStr: String): Long {
    val cleaned = dateStr.replace("T", " ").replace("Z", "").trim()
    val parts = cleaned.split(" ")
    val dateParts = parts[0].split("-")
    val timeParts = if (parts.size > 1) parts[1].split(":") else listOf("0", "0", "0")

    val year = dateParts[0].toInt()
    val month = dateParts[1].toInt()
    val day = dateParts[2].toInt()
    val hour = timeParts[0].toInt()
    val minute = timeParts[1].toInt()

    var totalDays = 0L
    for (y in 1970 until year) {
        totalDays += if (y % 4 == 0 && (y % 100 != 0 || y % 400 == 0)) 366 else 365
    }
    val daysInMonth = intArrayOf(0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)
    if (year % 4 == 0 && (year % 100 != 0 || year % 400 == 0) && month > 2) totalDays++
    for (m in 1 until month) totalDays += daysInMonth[m]
    totalDays += day - 1

    return totalDays * 86400000L + hour * 3600000L + minute * 60000L
}

fun formatTimeAgo(dateStr: String): String {
    return try {
        val diffMillis = System.currentTimeMillis() - parseToMillis(dateStr)
        when {
            diffMillis < 0 -> "just now"
            diffMillis < 60_000 -> "just now"
            diffMillis < 3_600_000 -> "${diffMillis / 60_000}m ago"
            diffMillis < 86_400_000 -> "${diffMillis / 3_600_000}h ago"
            diffMillis < 7 * 86_400_000L -> "${diffMillis / 86_400_000}d ago"
            else -> "${diffMillis / (7 * 86_400_000L)}w ago"
        }
    } catch (_: Exception) {
        ""
    }
}

fun isRecentPost(dateStr: String): Boolean {
    return try {
        System.currentTimeMillis() - parseToMillis(dateStr) < 86_400_000L
    } catch (_: Exception) {
        false
    }
}
