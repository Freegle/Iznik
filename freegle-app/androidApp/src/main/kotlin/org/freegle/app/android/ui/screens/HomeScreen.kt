package org.freegle.app.android.ui.screens

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.foundation.Canvas
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import android.Manifest
import android.content.pm.PackageManager
import android.location.LocationManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import coil3.compose.AsyncImage
import coil3.ImageLoader
import coil3.request.ImageRequest
import kotlinx.coroutines.launch
import org.freegle.app.android.data.FreeglePreferences
import org.freegle.app.android.ui.components.*
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.LocationResult
import org.freegle.app.model.MessageSummary
import org.freegle.app.repository.MessageRepository
import org.koin.compose.koinInject
import kotlin.math.abs
import kotlin.math.roundToInt

/** Number of curated daily picks. */
private const val DAILY_PICKS_COUNT = 5

enum class MessageFilter(val label: String, val types: List<String>?) {
    ALL("All", null),
    OFFERS("Offer", listOf("Offer")),
    WANTED("Wanted", listOf("Wanted")),
}

/** Display approximate distance in miles, matching iznik-nuxt3 format. */
internal fun milesAway(lat1: Double, lng1: Double, lat2: Double, lng2: Double): String? {
    if (lat1 == 0.0 || lat2 == 0.0) return null
    val km = haversineKm(lat1, lng1, lat2, lng2)
    val miles = km * 0.621371
    return when {
        miles < 1 -> "<1mi"
        else -> "${miles.roundToInt()}mi"
    }
}

/** Calculate straight-line distance between two lat/lng points using Haversine formula. */
internal fun haversineKm(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
    val r = 6371.0
    val dLat = kotlin.math.PI / 180 * (lat2 - lat1)
    val dLng = kotlin.math.PI / 180 * (lng2 - lng1)
    val a = kotlin.math.sin(dLat / 2) * kotlin.math.sin(dLat / 2) +
        kotlin.math.cos(kotlin.math.PI / 180 * lat1) *
        kotlin.math.cos(kotlin.math.PI / 180 * lat2) *
        kotlin.math.sin(dLng / 2) * kotlin.math.sin(dLng / 2)
    return r * 2 * kotlin.math.atan2(kotlin.math.sqrt(a), kotlin.math.sqrt(1 - a))
}

@Composable
fun HomeScreen(
    onMessageClick: (Long) -> Unit,
    onPostWantedClick: () -> Unit = {},
    onNavigateToExplore: () -> Unit = {},
    messageRepository: MessageRepository = koinInject(),
    api: FreegleApi = koinInject(),
    prefs: FreeglePreferences = koinInject(),
) {
    val messages by messageRepository.messages.collectAsState()
    val isLoading by messageRepository.isLoading.collectAsState()
    val error by messageRepository.error.collectAsState()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    var postcode by remember { mutableStateOf("") }
    var locationName by remember { mutableStateOf("") }
    var lat by remember { mutableStateOf(0.0) }
    var lng by remember { mutableStateOf(0.0) }
    var showPostcodeDialog by remember { mutableStateOf(false) }
    var searchRadius by remember { mutableStateOf(20.0) }
    var selectedFilter by remember { mutableStateOf(MessageFilter.ALL) }
    var isDetectingLocation by remember { mutableStateOf(false) }

    // Discovery Deck state
    var currentIndex by remember { mutableIntStateOf(0) }

    // Streak state
    var streakState by remember { mutableStateOf<FreeglePreferences.StreakState?>(null) }
    LaunchedEffect(Unit) {
        streakState = prefs.getStreakState()
    }

    // Restore saved location on first launch, or auto-detect GPS
    LaunchedEffect(Unit) {
        val savedPostcode = prefs.getPostcode()
        if (savedPostcode.isNotEmpty()) {
            postcode = savedPostcode
            locationName = prefs.getLocationName()
            lat = prefs.getLat()
            lng = prefs.getLng()
        } else {
            val hasPermission = ContextCompat.checkSelfPermission(
                context, Manifest.permission.ACCESS_COARSE_LOCATION
            ) == PackageManager.PERMISSION_GRANTED
            if (hasPermission) {
                isDetectingLocation = true
                detectAndSetLocation(context, api, prefs) { pc, name, newLat, newLng ->
                    if (pc.isNotEmpty()) {
                        postcode = pc
                        locationName = name
                        lat = newLat
                        lng = newLng
                    }
                    isDetectingLocation = false
                }
            }
        }
    }

    // GPS location permission launcher
    val locationPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) {
            isDetectingLocation = true
            scope.launch {
                detectAndSetLocation(context, api, prefs) { pc, name, newLat, newLng ->
                    if (pc.isNotEmpty()) {
                        postcode = pc
                        locationName = name
                        lat = newLat
                        lng = newLng
                    } else {
                        showPostcodeDialog = true
                    }
                    isDetectingLocation = false
                }
            }
        } else {
            showPostcodeDialog = true
        }
    }

    // Cache user info (userId -> displayname, profileUrl)
    val userCache = remember { mutableStateMapOf<Long, Pair<String?, String?>>() }
    LaunchedEffect(lat, lng, selectedFilter, searchRadius) {
        if (lat != 0.0 && lng != 0.0) {
            currentIndex = 0
            messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types)
        }
    }

    // Pre-fetch user info and preload images
    val imageLoader = ImageLoader(context)
    LaunchedEffect(messages) {
        val userIds = messages.mapNotNull { it.fromuser }.distinct()
        for (userId in userIds) {
            if (userId !in userCache) {
                val user = api.getUser(userId)
                userCache[userId] = Pair(
                    user?.displayname ?: user?.firstname,
                    user?.profile?.url ?: user?.profile?.paththumb,
                )
            }
        }
        for (msg in messages) {
            val url = msg.messageAttachments?.firstOrNull()?.path
                ?: msg.messageAttachments?.firstOrNull()?.paththumb
            if (url != null) {
                imageLoader.enqueue(ImageRequest.Builder(context).data(url).build())
            }
        }
    }

    if (showPostcodeDialog) {
        PostcodeDialog(
            currentPostcode = postcode,
            api = api,
            onDismiss = { showPostcodeDialog = false },
            onPostcodeSet = { pc, name, newLat, newLng ->
                postcode = pc
                locationName = name
                lat = newLat
                lng = newLng
                showPostcodeDialog = false
                scope.launch { prefs.saveLocation(pc, name, newLat, newLng) }
            },
        )
    }

    // Deduplicate and prepare card list
    val allItems = remember(messages) {
        messages.distinctBy { it.id }
            .sortedByDescending { it.arrival ?: it.date }
    }

    // Daily 5 curated picks
    val dailyPicks = remember(allItems, lat, lng) {
        selectDailyPicks(allItems, lat, lng)
    }
    val totalPicks = dailyPicks.size

    Column(modifier = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        when {
            postcode.isEmpty() -> {
                LocationEmptyState(
                    isDetecting = isDetectingLocation,
                    onDetectLocation = {
                        val hasPermission = ContextCompat.checkSelfPermission(
                            context, Manifest.permission.ACCESS_COARSE_LOCATION
                        ) == PackageManager.PERMISSION_GRANTED
                        if (hasPermission) {
                            isDetectingLocation = true
                            scope.launch {
                                detectAndSetLocation(context, api, prefs) { pc, name, newLat, newLng ->
                                    if (pc.isNotEmpty()) {
                                        postcode = pc
                                        locationName = name
                                        lat = newLat
                                        lng = newLng
                                    } else {
                                        showPostcodeDialog = true
                                    }
                                    isDetectingLocation = false
                                }
                            }
                        } else {
                            locationPermissionLauncher.launch(Manifest.permission.ACCESS_COARSE_LOCATION)
                        }
                    },
                    onSetPostcode = { showPostcodeDialog = true },
                )
            }
            isLoading && messages.isEmpty() -> {
                DeckSkeletonLoading()
            }
            messages.isEmpty() && !isLoading -> {
                NoItemsState(
                    onPostWanted = onPostWantedClick,
                    onExpandSearch = { searchRadius = 40.0 },
                    onChangePostcode = { showPostcodeDialog = true },
                )
            }
            else -> {
                // Header: location + progress dots + streak
                DailyPicksHeader(
                    locationName = locationName,
                    postcode = postcode,
                    onChangeLocation = { showPostcodeDialog = true },
                    currentPosition = currentIndex,
                    totalPicks = totalPicks,
                    dailyPicks = dailyPicks,
                    streakState = streakState,
                )

                // Discovery Deck area
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .weight(1f),
                ) {
                    if (currentIndex >= totalPicks) {
                        // Record daily completion for streak
                        LaunchedEffect(Unit) {
                            prefs.recordDailyCompletion()
                            streakState = prefs.getStreakState()
                        }

                        // Finished daily picks
                        DailyPicksComplete(
                            seenCount = totalPicks,
                            totalAvailable = allItems.size,
                            streakState = streakState,
                            onBrowseAll = onNavigateToExplore,
                            onPostWanted = onPostWantedClick,
                        )
                    } else {
                        // Card area
                        Box(
                            modifier = Modifier
                                .fillMaxWidth()
                                .weight(1f),
                            contentAlignment = Alignment.Center,
                        ) {
                            // Next card behind (if exists)
                            if (currentIndex + 1 < totalPicks) {
                                val nextPick = dailyPicks[currentIndex + 1]
                                val nextMsg = nextPick.message
                                DiscoveryCard(
                                    message = nextMsg,
                                    userName = userCache[nextMsg.fromuser]?.first,
                                    userProfileUrl = userCache[nextMsg.fromuser]?.second,
                                    userLat = lat,
                                    userLng = lng,
                                    category = nextPick.category,
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(horizontal = 24.dp)
                                        .scale(0.95f),
                                    isBackground = true,
                                )
                            }

                            // Current card (swipeable + tappable)
                            val pick = dailyPicks[currentIndex]
                            val msg = pick.message
                            SwipeableCard(
                                message = msg,
                                userName = userCache[msg.fromuser]?.first,
                                userProfileUrl = userCache[msg.fromuser]?.second,
                                userLat = lat,
                                userLng = lng,
                                category = pick.category,
                                onSwipeRight = { onMessageClick(msg.id) },
                                onSwipeLeft = { currentIndex++ },
                                onTap = { onMessageClick(msg.id) },
                                onShare = {
                                    shareItem(context, cleanTitle(msg.subject ?: "Item"), msg.id)
                                },
                            )
                        }

                        // Action buttons below the card
                        ActionButtons(
                            onSkip = { currentIndex++ },
                            onInterested = {
                                val msg = dailyPicks[currentIndex].message
                                onMessageClick(msg.id)
                            },
                        )
                    }

                    // Error snackbar
                    if (error != null) {
                        Surface(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(horizontal = 16.dp, vertical = 4.dp),
                            shape = RoundedCornerShape(12.dp),
                            color = MaterialTheme.colorScheme.errorContainer,
                        ) {
                            Row(
                                modifier = Modifier.padding(12.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Icon(Icons.Default.Warning, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(20.dp))
                                Spacer(Modifier.width(8.dp))
                                Text(error ?: "", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onErrorContainer, modifier = Modifier.weight(1f))
                                TextButton(onClick = {
                                    scope.launch { messageRepository.loadLocalMessages(lat, lng, radiusKm = searchRadius, types = selectedFilter.types) }
                                }) { Text("Retry") }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun DailyPicksHeader(
    locationName: String,
    postcode: String,
    onChangeLocation: () -> Unit,
    currentPosition: Int,
    totalPicks: Int,
    dailyPicks: List<DailyPick>,
    streakState: FreeglePreferences.StreakState?,
) {
    Surface(
        color = MaterialTheme.colorScheme.surface,
        shadowElevation = 2.dp,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 10.dp),
        ) {
            // Single row: location | progress dots | streak
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.fillMaxWidth(),
            ) {
                // Location (tappable)
                Icon(
                    Icons.Default.LocationOn,
                    contentDescription = null,
                    modifier = Modifier.size(16.dp),
                    tint = MaterialTheme.colorScheme.primary,
                )
                Spacer(Modifier.width(4.dp))
                Text(
                    locationName.ifEmpty { postcode },
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.primary,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clickable(onClick = onChangeLocation),
                )

                Spacer(Modifier.weight(1f))

                // Progress dots — prominent size
                Row(
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    repeat(totalPicks.coerceAtMost(DAILY_PICKS_COUNT)) { index ->
                        val isSeen = index < currentPosition
                        val isCurrent = index == currentPosition

                        Box(
                            modifier = Modifier
                                .size(if (isCurrent) 16.dp else 12.dp)
                                .background(
                                    color = when {
                                        isSeen -> MaterialTheme.colorScheme.primary
                                        isCurrent -> MaterialTheme.colorScheme.primary.copy(alpha = 0.4f)
                                        else -> MaterialTheme.colorScheme.surfaceVariant
                                    },
                                    shape = CircleShape,
                                ),
                        ) {
                            if (isSeen) {
                                Icon(
                                    Icons.Default.Check,
                                    contentDescription = null,
                                    modifier = Modifier.size(if (isCurrent) 10.dp else 8.dp).align(Alignment.Center),
                                    tint = Color.White,
                                )
                            }
                        }
                    }
                }

                // Streak badge (compact)
                if (streakState != null && streakState.count > 0) {
                    Spacer(Modifier.width(8.dp))
                    Icon(
                        Icons.Default.LocalFireDepartment,
                        contentDescription = null,
                        modifier = Modifier.size(18.dp),
                        tint = Color(0xFFFF6D00),
                    )
                    Text(
                        "${streakState.count}",
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFFE65100),
                    )
                }
            }
        }
    }
}

@Composable
private fun ActionButtons(
    onSkip: () -> Unit,
    onInterested: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 40.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.SpaceEvenly,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // Skip button
        FilledTonalIconButton(
            onClick = onSkip,
            modifier = Modifier.size(64.dp),
            shape = CircleShape,
            colors = IconButtonDefaults.filledTonalIconButtonColors(
                containerColor = MaterialTheme.colorScheme.errorContainer,
            ),
        ) {
            Icon(
                Icons.Default.Close,
                contentDescription = "Skip",
                modifier = Modifier.size(32.dp),
                tint = MaterialTheme.colorScheme.error,
            )
        }

        // Interested button
        FilledIconButton(
            onClick = onInterested,
            modifier = Modifier.size(72.dp),
            shape = CircleShape,
            colors = IconButtonDefaults.filledIconButtonColors(
                containerColor = Color(0xFF008040),
            ),
        ) {
            Icon(
                Icons.Default.Favorite,
                contentDescription = "I want this!",
                modifier = Modifier.size(36.dp),
                tint = Color.White,
            )
        }
    }
}

@Composable
private fun SwipeableCard(
    message: MessageSummary,
    userName: String?,
    userProfileUrl: String?,
    userLat: Double,
    userLng: Double,
    category: PickCategory,
    onSwipeRight: () -> Unit,
    onSwipeLeft: () -> Unit,
    onTap: () -> Unit,
    onShare: () -> Unit,
) {
    var offsetX by remember { mutableFloatStateOf(0f) }
    var offsetY by remember { mutableFloatStateOf(0f) }
    var isDragging by remember { mutableStateOf(false) }

    // Animate back to center when released below threshold
    val animatedOffsetX by animateFloatAsState(
        targetValue = if (isDragging) offsetX else 0f,
        animationSpec = spring(dampingRatio = 0.7f, stiffness = 300f),
        label = "card_x",
    )
    val animatedOffsetY by animateFloatAsState(
        targetValue = if (isDragging) offsetY else 0f,
        animationSpec = spring(dampingRatio = 0.7f, stiffness = 300f),
        label = "card_y",
    )

    val rotation = (animatedOffsetX / 40f).coerceIn(-15f, 15f)
    val swipeProgress = (animatedOffsetX / 300f).coerceIn(-1f, 1f)

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp)
            .offset { IntOffset(animatedOffsetX.roundToInt(), animatedOffsetY.roundToInt()) }
            .graphicsLayer { rotationZ = rotation }
            .clickable(onClick = onTap)
            .pointerInput(message.id) {
                detectDragGestures(
                    onDragStart = { isDragging = true },
                    onDrag = { change, dragAmount ->
                        change.consume()
                        offsetX += dragAmount.x
                        offsetY += dragAmount.y
                    },
                    onDragEnd = {
                        when {
                            offsetX > 150f -> {
                                isDragging = false
                                offsetX = 0f
                                offsetY = 0f
                                onSwipeRight()
                            }
                            offsetX < -150f -> {
                                isDragging = false
                                offsetX = 0f
                                offsetY = 0f
                                onSwipeLeft()
                            }
                            else -> {
                                isDragging = false
                                offsetX = 0f
                                offsetY = 0f
                            }
                        }
                    },
                    onDragCancel = {
                        isDragging = false
                        offsetX = 0f
                        offsetY = 0f
                    },
                )
            },
    ) {
        DiscoveryCard(
            message = message,
            userName = userName,
            userProfileUrl = userProfileUrl,
            userLat = userLat,
            userLng = userLng,
            category = category,
            onShare = onShare,
        )

        // Swipe direction indicators
        if (abs(swipeProgress) > 0.15f) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .aspectRatio(0.75f)
                    .clip(RoundedCornerShape(24.dp)),
                contentAlignment = Alignment.Center,
            ) {
                if (swipeProgress > 0.15f) {
                    Box(
                        modifier = Modifier
                            .align(Alignment.CenterStart)
                            .padding(start = 24.dp)
                            .size(72.dp)
                            .background(
                                Color(0xFF008040).copy(alpha = (swipeProgress * 0.8f).coerceIn(0f, 0.8f)),
                                CircleShape,
                            ),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Default.Favorite, contentDescription = "Interested", tint = Color.White, modifier = Modifier.size(36.dp))
                    }
                } else if (swipeProgress < -0.15f) {
                    Box(
                        modifier = Modifier
                            .align(Alignment.CenterEnd)
                            .padding(end = 24.dp)
                            .size(72.dp)
                            .background(
                                Color(0xFFD32F2F).copy(alpha = (abs(swipeProgress) * 0.8f).coerceIn(0f, 0.8f)),
                                CircleShape,
                            ),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Default.Close, contentDescription = "Skip", tint = Color.White, modifier = Modifier.size(36.dp))
                    }
                }
            }
        }
    }
}

@Composable
private fun DiscoveryCard(
    message: MessageSummary,
    userName: String?,
    userProfileUrl: String?,
    userLat: Double,
    userLng: Double,
    category: PickCategory? = null,
    modifier: Modifier = Modifier,
    isBackground: Boolean = false,
    onShare: (() -> Unit)? = null,
) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.path
        ?: message.messageAttachments?.firstOrNull()?.paththumb
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"
    val timeStr = message.arrival ?: message.date
    val name = userName ?: "A Freegler"
    val itemLocation = message.location?.areaname ?: extractLocation(message.subject)

    val distanceStr = milesAway(userLat, userLng, message.lat ?: 0.0, message.lng ?: 0.0)

    Card(
        modifier = modifier.then(Modifier.fillMaxWidth()),
        shape = RoundedCornerShape(24.dp),
        elevation = CardDefaults.cardElevation(
            defaultElevation = if (isBackground) 2.dp else 6.dp,
        ),
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .aspectRatio(0.75f),
        ) {
            // Full-bleed photo
            if (imageUrl != null) {
                AsyncImage(
                    model = imageUrl,
                    contentDescription = title,
                    modifier = Modifier.fillMaxSize(),
                    contentScale = ContentScale.Crop,
                )
            } else {
                // Question mark placeholder matching iznik-nuxt3 style
                QuestionMarkPlaceholder(isOffer = isOffer, modifier = Modifier.fillMaxSize())
            }

            // "NEW" badge for recent posts
            if (timeStr != null && isRecentPost(timeStr)) {
                Surface(
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .padding(16.dp),
                    shape = RoundedCornerShape(8.dp),
                    color = Color(0xFFFF6D00),
                ) {
                    Text(
                        "NEW",
                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
                        style = MaterialTheme.typography.labelMedium,
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // Top right: share button + type badge
            Row(
                modifier = Modifier
                    .align(Alignment.TopEnd)
                    .padding(16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                // Share button
                if (onShare != null && !isBackground) {
                    Surface(
                        shape = CircleShape,
                        color = Color.Black.copy(alpha = 0.4f),
                        modifier = Modifier
                            .size(36.dp)
                            .clickable(onClick = onShare),
                    ) {
                        Box(contentAlignment = Alignment.Center, modifier = Modifier.fillMaxSize()) {
                            Icon(
                                Icons.Default.Share,
                                contentDescription = "Share",
                                modifier = Modifier.size(18.dp),
                                tint = Color.White,
                            )
                        }
                    }
                }

                // Type badge (Offer/Wanted)
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                ) {
                    Text(
                        if (isOffer) "OFFER" else "WANTED",
                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
                        style = MaterialTheme.typography.labelMedium,
                        color = Color.White,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            // Bottom gradient overlay
            Box(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .fillMaxHeight(0.40f)
                    .background(
                        Brush.verticalGradient(
                            colors = listOf(Color.Transparent, Color.Black.copy(alpha = 0.85f)),
                        ),
                    ),
            )

            // Bottom content
            Column(
                modifier = Modifier
                    .align(Alignment.BottomStart)
                    .fillMaxWidth()
                    .padding(20.dp),
            ) {
                // Category badge — visually prominent pill
                if (category != null) {
                    Surface(
                        shape = RoundedCornerShape(16.dp),
                        color = category.color,
                        shadowElevation = 4.dp,
                    ) {
                        Row(
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            Icon(
                                category.icon,
                                contentDescription = null,
                                modifier = Modifier.size(18.dp),
                                tint = Color.White,
                            )
                            Text(
                                category.label,
                                style = MaterialTheme.typography.labelLarge,
                                color = Color.White,
                                fontWeight = FontWeight.Bold,
                            )
                        }
                    }
                    Spacer(Modifier.height(6.dp))
                }

                // Item title
                Text(
                    title,
                    style = MaterialTheme.typography.headlineMedium,
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )

                Spacer(Modifier.height(6.dp))

                // Distance + location
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Icon(
                        Icons.Default.LocationOn,
                        contentDescription = null,
                        modifier = Modifier.size(18.dp),
                        tint = Color.White.copy(alpha = 0.9f),
                    )
                    Text(
                        distanceStr ?: itemLocation ?: "",
                        style = MaterialTheme.typography.bodyLarge,
                        color = Color.White.copy(alpha = 0.9f),
                        fontWeight = FontWeight.Medium,
                    )
                    if (distanceStr != null && itemLocation != null) {
                        Text("\u00b7", color = Color.White.copy(alpha = 0.6f), style = MaterialTheme.typography.bodyLarge)
                        Text(itemLocation, style = MaterialTheme.typography.bodyLarge, color = Color.White.copy(alpha = 0.7f))
                    }
                }

                Spacer(Modifier.height(8.dp))

                // Giver info row
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    if (userProfileUrl != null) {
                        AsyncImage(
                            model = userProfileUrl,
                            contentDescription = name,
                            modifier = Modifier.size(32.dp).clip(CircleShape),
                            contentScale = ContentScale.Crop,
                        )
                    } else {
                        Box(
                            modifier = Modifier.size(32.dp).background(Color.White.copy(alpha = 0.3f), CircleShape),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(name.first().uppercase(), style = MaterialTheme.typography.labelMedium, color = Color.White, fontWeight = FontWeight.Bold)
                        }
                    }

                    Text(name, style = MaterialTheme.typography.titleSmall, color = Color.White, fontWeight = FontWeight.SemiBold)

                    if (timeStr != null) {
                        Text("\u00b7", color = Color.White.copy(alpha = 0.5f))
                        Text(formatTimeAgo(timeStr), style = MaterialTheme.typography.bodySmall, color = Color.White.copy(alpha = 0.7f))
                    }

                    Spacer(Modifier.weight(1f))

                    // Qualitative interest instead of raw count
                    val interest = qualitativeInterest(message.replycount)
                    if (message.replycount > 0) {
                        Surface(shape = RoundedCornerShape(12.dp), color = Color.White.copy(alpha = 0.2f)) {
                            Row(
                                modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(4.dp),
                            ) {
                                Icon(Icons.Default.Favorite, contentDescription = null, modifier = Modifier.size(12.dp), tint = Color.White)
                                Text(interest, style = MaterialTheme.typography.labelSmall, color = Color.White)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun DailyPicksComplete(
    seenCount: Int,
    totalAvailable: Int,
    streakState: FreeglePreferences.StreakState?,
    onBrowseAll: () -> Unit,
    onPostWanted: () -> Unit,
) {
    // Animated scale-in for celebration
    val scale by animateFloatAsState(
        targetValue = 1f,
        animationSpec = spring(dampingRatio = 0.6f, stiffness = 200f),
        label = "celebration_scale",
    )

    Box(
        modifier = Modifier.fillMaxSize(),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier
                .padding(40.dp)
                .scale(scale),
        ) {
            // Celebration icon
            Box(
                modifier = Modifier
                    .size(100.dp)
                    .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Default.CheckCircle,
                    contentDescription = null,
                    modifier = Modifier.size(52.dp),
                    tint = MaterialTheme.colorScheme.primary,
                )
            }
            Spacer(Modifier.height(24.dp))
            Text(
                "All caught up!",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(8.dp))
            Text(
                "You've seen today's $seenCount picks. Come back tomorrow for a fresh batch!",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )

            // Streak celebration
            if (streakState != null && streakState.count > 0) {
                Spacer(Modifier.height(16.dp))
                Surface(
                    shape = RoundedCornerShape(16.dp),
                    color = Color(0xFFFFF3E0),
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 20.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(
                            Icons.Default.LocalFireDepartment,
                            contentDescription = null,
                            modifier = Modifier.size(28.dp),
                            tint = Color(0xFFFF6D00),
                        )
                        Column {
                            Text(
                                "You're on a ${streakState.count}-day streak!",
                                style = MaterialTheme.typography.titleSmall,
                                fontWeight = FontWeight.Bold,
                                color = Color(0xFFE65100),
                            )
                            if (streakState.best > streakState.count) {
                                Text(
                                    "Best: ${streakState.best} days",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = Color(0xFFE65100).copy(alpha = 0.7f),
                                )
                            }
                        }
                    }
                }
            }

            if (totalAvailable > seenCount) {
                Spacer(Modifier.height(8.dp))
                Text(
                    "${totalAvailable - seenCount} more items available in Explore",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.primary,
                    fontWeight = FontWeight.Medium,
                    textAlign = TextAlign.Center,
                )
            }

            Spacer(Modifier.height(24.dp))
            Button(
                onClick = onBrowseAll,
                modifier = Modifier.fillMaxWidth(0.7f),
            ) {
                Icon(Icons.Default.Explore, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Browse all items")
            }
            Spacer(Modifier.height(8.dp))
            OutlinedButton(
                onClick = onPostWanted,
                modifier = Modifier.fillMaxWidth(0.7f),
            ) {
                Icon(Icons.Default.PostAdd, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Post a Wanted")
            }
        }
    }
}

@Composable
private fun DeckSkeletonLoading() {
    val shimmerColors = listOf(
        MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.6f),
        MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.2f),
        MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.6f),
    )
    val transition = rememberInfiniteTransition(label = "shimmer")
    val translateAnim by transition.animateFloat(
        initialValue = 0f,
        targetValue = 1000f,
        animationSpec = infiniteRepeatable(
            animation = tween(1200, easing = LinearEasing),
            repeatMode = RepeatMode.Restart,
        ),
        label = "shimmer_translate",
    )
    val brush = Brush.linearGradient(
        colors = shimmerColors,
        start = androidx.compose.ui.geometry.Offset(translateAnim - 200f, 0f),
        end = androidx.compose.ui.geometry.Offset(translateAnim, 0f),
    )

    Column(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        // Progress dots skeleton
        Spacer(Modifier.height(16.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            repeat(5) {
                Box(
                    modifier = Modifier
                        .size(10.dp)
                        .clip(CircleShape)
                        .background(brush),
                )
            }
        }
        Spacer(Modifier.height(16.dp))

        // Card skeleton
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .weight(1f)
                .clip(RoundedCornerShape(24.dp))
                .background(brush),
        ) {
            Column(
                modifier = Modifier
                    .align(Alignment.BottomStart)
                    .fillMaxWidth()
                    .padding(20.dp),
            ) {
                Box(Modifier.width(200.dp).height(28.dp).clip(RoundedCornerShape(8.dp)).background(Color.White.copy(alpha = 0.2f)))
                Spacer(Modifier.height(8.dp))
                Box(Modifier.width(140.dp).height(16.dp).clip(RoundedCornerShape(6.dp)).background(Color.White.copy(alpha = 0.15f)))
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Box(Modifier.size(32.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.15f)))
                    Box(Modifier.width(100.dp).height(14.dp).clip(RoundedCornerShape(4.dp)).background(Color.White.copy(alpha = 0.15f)))
                }
            }
        }

        // Button skeleton
        Spacer(Modifier.height(12.dp))
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 40.dp),
            horizontalArrangement = Arrangement.SpaceEvenly,
        ) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(brush))
            Box(Modifier.size(72.dp).clip(CircleShape).background(brush))
        }
        Spacer(Modifier.height(12.dp))
    }
}

@Composable
private fun LocationEmptyState(
    isDetecting: Boolean,
    onDetectLocation: () -> Unit,
    onSetPostcode: () -> Unit,
) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(120.dp)
                    .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                if (isDetecting) {
                    CircularProgressIndicator(modifier = Modifier.size(48.dp), color = MaterialTheme.colorScheme.primary)
                } else {
                    Icon(Icons.Default.LocationOn, contentDescription = null, modifier = Modifier.size(60.dp), tint = MaterialTheme.colorScheme.primary)
                }
            }
            Spacer(Modifier.height(32.dp))
            Text(
                if (isDetecting) "Finding your location..." else "What's free nearby?",
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                if (isDetecting) "We're detecting your location so we can show you free items nearby."
                else "Discover free items from your neighbours. Swipe through what's available near you.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(32.dp))
            if (!isDetecting) {
                Button(
                    onClick = onDetectLocation,
                    modifier = Modifier.fillMaxWidth(0.75f).height(52.dp),
                ) {
                    Icon(Icons.Default.LocationOn, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Find items near me", style = MaterialTheme.typography.titleSmall)
                }
                Spacer(Modifier.height(12.dp))
                TextButton(onClick = onSetPostcode) { Text("Enter postcode instead") }
            }
        }
    }
}

@Composable
private fun NoItemsState(
    onPostWanted: () -> Unit,
    onExpandSearch: () -> Unit,
    onChangePostcode: () -> Unit,
) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            Text("Nothing here yet", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
            Spacer(Modifier.height(8.dp))
            Text(
                "New items pop up every day. Or be the first to give something in your area!",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(24.dp))
            Button(onClick = onPostWanted) { Text("Post a Wanted ad") }
            Spacer(Modifier.height(8.dp))
            OutlinedButton(onClick = onExpandSearch) { Text("Search wider area") }
            Spacer(Modifier.height(8.dp))
            TextButton(onClick = onChangePostcode) { Text("Change postcode") }
        }
    }
}

@Suppress("MissingPermission")
private suspend fun detectAndSetLocation(
    context: android.content.Context,
    api: FreegleApi,
    prefs: FreeglePreferences,
    onResult: (postcode: String, locationName: String, lat: Double, lng: Double) -> Unit,
) {
    try {
        val locationManager = context.getSystemService(android.content.Context.LOCATION_SERVICE) as LocationManager
        val lastLocation = locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER)
            ?: locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)

        if (lastLocation != null) {
            val response = api.resolveLocation(lastLocation.latitude, lastLocation.longitude)
            val loc = response?.location ?: response?.locations?.firstOrNull()
            if (loc != null) {
                val pc = loc.name ?: ""
                val name = loc.areaname ?: loc.area?.name ?: ""
                prefs.saveLocation(pc, name, loc.lat, loc.lng)
                onResult(pc, name, loc.lat, loc.lng)
                return
            }
        }
        onResult("", "", 0.0, 0.0)
    } catch (_: Exception) {
        onResult("", "", 0.0, 0.0)
    }
}

/**
 * Question mark placeholder matching iznik-nuxt3 style: gradient background with
 * repeating ? pattern at low opacity and a centered category icon.
 */
@Composable
internal fun QuestionMarkPlaceholder(
    isOffer: Boolean,
    modifier: Modifier = Modifier,
) {
    val gradientColors = if (isOffer) {
        listOf(Color(0xFF003318), Color(0xFF006633), Color(0xFF008040))
    } else {
        listOf(Color(0xFF0D2B4D), Color(0xFF0D47A1), Color(0xFF1565C0))
    }

    Box(
        modifier = modifier.background(Brush.verticalGradient(gradientColors)),
        contentAlignment = Alignment.Center,
    ) {
        // Repeating ? pattern at low opacity
        Column(
            modifier = Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.SpaceEvenly,
        ) {
            repeat(7) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceEvenly,
                ) {
                    repeat(5) {
                        Text(
                            "?",
                            style = MaterialTheme.typography.headlineLarge,
                            color = Color.White.copy(alpha = 0.12f),
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }
            }
        }

        // Centered icon with frosted circle
        Box(
            modifier = Modifier
                .size(80.dp)
                .background(Color.White.copy(alpha = 0.15f), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                if (isOffer) Icons.Default.CardGiftcard else Icons.Default.Search,
                contentDescription = null,
                modifier = Modifier.size(40.dp),
                tint = Color.White.copy(alpha = 0.7f),
            )
        }
    }
}

@Composable
fun PostcodeDialog(
    currentPostcode: String,
    api: FreegleApi,
    onDismiss: () -> Unit,
    onPostcodeSet: (postcode: String, locationName: String, lat: Double, lng: Double) -> Unit,
) {
    var postcodeInput by remember { mutableStateOf(currentPostcode) }
    var isLooking by remember { mutableStateOf(false) }
    var lookupError by remember { mutableStateOf<String?>(null) }
    var suggestions by remember { mutableStateOf<List<LocationResult>>(emptyList()) }
    val scope = rememberCoroutineScope()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Your postcode") },
        text = {
            Column {
                Text(
                    "Enter your postcode to find free items nearby.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(16.dp))
                OutlinedTextField(
                    value = postcodeInput,
                    onValueChange = { input ->
                        postcodeInput = input.uppercase()
                        lookupError = null
                        if (input.length >= 2) {
                            scope.launch { suggestions = api.searchLocations(input.trim()) }
                        } else {
                            suggestions = emptyList()
                        }
                    },
                    label = { Text("Postcode") },
                    placeholder = { Text("e.g. EH1 1AA") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                    isError = lookupError != null,
                    supportingText = lookupError?.let { { Text(it) } },
                )
                if (suggestions.isNotEmpty()) {
                    Spacer(Modifier.height(8.dp))
                    LazyColumn(modifier = Modifier.heightIn(max = 200.dp)) {
                        items(suggestions) { location ->
                            ListItem(
                                headlineContent = { Text(location.name ?: "", style = MaterialTheme.typography.bodyMedium) },
                                supportingContent = {
                                    val area = location.areaname ?: location.area?.name
                                    if (area != null) Text(area, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                },
                                leadingContent = { Icon(Icons.Default.LocationOn, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(20.dp)) },
                                modifier = Modifier.clickable {
                                    val name = location.areaname ?: location.area?.name ?: ""
                                    onPostcodeSet(location.name ?: "", name, location.lat, location.lng)
                                },
                            )
                            HorizontalDivider()
                        }
                    }
                }
            }
        },
        confirmButton = {
            Button(
                onClick = {
                    if (postcodeInput.isBlank()) { lookupError = "Please enter a postcode"; return@Button }
                    val first = suggestions.firstOrNull()
                    if (first != null) {
                        val name = first.areaname ?: first.area?.name ?: ""
                        onPostcodeSet(first.name ?: postcodeInput, name, first.lat, first.lng)
                    } else {
                        isLooking = true; lookupError = null
                        scope.launch {
                            val results = api.searchLocations(postcodeInput.trim())
                            isLooking = false
                            val match = results.firstOrNull()
                            if (match != null) {
                                val name = match.areaname ?: match.area?.name ?: ""
                                onPostcodeSet(match.name ?: postcodeInput, name, match.lat, match.lng)
                            } else {
                                lookupError = "Postcode not found. Check and try again."
                            }
                        }
                    }
                },
                enabled = !isLooking && postcodeInput.isNotBlank(),
            ) {
                if (isLooking) CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                else Text("Find items")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
