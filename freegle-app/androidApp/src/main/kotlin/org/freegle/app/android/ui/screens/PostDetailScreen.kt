package org.freegle.app.android.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch
import org.freegle.app.android.ui.components.cleanTitle
import org.freegle.app.android.ui.components.extractLocation
import org.freegle.app.android.ui.components.formatTimeAgo
import org.freegle.app.android.ui.components.qualitativeInterest
import org.freegle.app.android.ui.components.shareItem
import org.freegle.app.api.FreegleApi
import org.freegle.app.model.MessageSummary
import org.freegle.app.model.User
import org.freegle.app.repository.MessageRepository
import org.koin.compose.koinInject

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PostDetailScreen(
    messageId: Long,
    onBack: () -> Unit,
    onChatClick: (Long) -> Unit,
    onMessageClick: ((Long) -> Unit)? = null,
    messageRepository: MessageRepository = koinInject(),
    api: FreegleApi = koinInject(),
) {
    var message by remember { mutableStateOf<MessageSummary?>(null) }
    var isLoading by remember { mutableStateOf(true) }
    var showInterestSent by remember { mutableStateOf(false) }
    var isSendingInterest by remember { mutableStateOf(false) }
    var sendError by remember { mutableStateOf<String?>(null) }
    var userMessage by remember { mutableStateOf("") }
    var poster by remember { mutableStateOf<User?>(null) }
    var otherPosts by remember { mutableStateOf<List<MessageSummary>>(emptyList()) }
    val scope = rememberCoroutineScope()
    val focusRequester = remember { FocusRequester() }
    val context = LocalContext.current

    LaunchedEffect(messageId) {
        isLoading = true
        message = messageRepository.getMessageDetail(messageId)
        isLoading = false
        message?.fromuser?.let { userId ->
            poster = api.getUser(userId)
            // Fetch other posts from this user
            val allMessages = messageRepository.messages.value
            otherPosts = allMessages
                .filter { it.fromuser == userId && it.id != messageId }
                .take(10)
        }
        // Auto-focus the reply input after content loads
        kotlinx.coroutines.delay(300)
        try { focusRequester.requestFocus() } catch (_: Exception) { }
    }

    if (isLoading) {
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator()
        }
        return
    }

    if (message == null) {
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text("Item not found", style = MaterialTheme.typography.bodyLarge)
                Spacer(Modifier.height(8.dp))
                Button(onClick = onBack) { Text("Go back") }
            }
        }
        return
    }

    val msg = message!!
    val isOffer = msg.type == "Offer"
    val title = cleanTitle(msg.subject ?: "Item")

    Scaffold(
        topBar = {
            TopAppBar(
                title = {},
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    // Share button in top bar
                    IconButton(onClick = {
                        shareItem(context, title, messageId)
                    }) {
                        Icon(Icons.Default.Share, contentDescription = "Share")
                    }
                },
            )
        },
        bottomBar = {
            if (!showInterestSent) {
                Surface(
                    color = MaterialTheme.colorScheme.surface,
                    shadowElevation = 8.dp,
                ) {
                    Column(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 16.dp, vertical = 12.dp)
                            .navigationBarsPadding(),
                    ) {
                        if (sendError != null) {
                            Card(
                                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer),
                                shape = RoundedCornerShape(8.dp),
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(bottom = 8.dp),
                            ) {
                                Row(
                                    modifier = Modifier.padding(8.dp),
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Icon(Icons.Default.Warning, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(16.dp))
                                    Spacer(Modifier.width(6.dp))
                                    Text(sendError!!, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onErrorContainer)
                                }
                            }
                        }
                        Row(
                            verticalAlignment = Alignment.Bottom,
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            OutlinedTextField(
                                value = userMessage,
                                onValueChange = { userMessage = it },
                                modifier = Modifier
                                    .weight(1f)
                                    .heightIn(min = 48.dp, max = 120.dp)
                                    .focusRequester(focusRequester),
                                placeholder = {
                                    Text(
                                        if (isOffer) "Say why you\u2019d like this and when you could collect"
                                        else "Say what you have and when they could collect",
                                        color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.4f),
                                        style = MaterialTheme.typography.bodyMedium,
                                    )
                                },
                                shape = RoundedCornerShape(24.dp),
                                maxLines = 3,
                            )
                            FilledIconButton(
                                onClick = {
                                    if (!isSendingInterest && userMessage.isNotBlank()) {
                                        isSendingInterest = true
                                        sendError = null
                                        scope.launch {
                                            try {
                                                val success = api.replyToMessage(messageId, userMessage)
                                                if (success) {
                                                    showInterestSent = true
                                                } else {
                                                    sendError = "Couldn\u2019t send. Please try again."
                                                }
                                            } catch (_: Exception) {
                                                sendError = "No connection. Check your internet."
                                            }
                                            isSendingInterest = false
                                        }
                                    }
                                },
                                enabled = !isSendingInterest && userMessage.isNotBlank(),
                                modifier = Modifier.size(48.dp),
                                shape = CircleShape,
                                colors = IconButtonDefaults.filledIconButtonColors(
                                    containerColor = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                                ),
                            ) {
                                if (isSendingInterest) {
                                    CircularProgressIndicator(
                                        modifier = Modifier.size(20.dp),
                                        color = Color.White,
                                        strokeWidth = 2.dp,
                                    )
                                } else {
                                    Icon(
                                        Icons.AutoMirrored.Filled.Send,
                                        contentDescription = "Send message",
                                        tint = Color.White,
                                        modifier = Modifier.size(20.dp),
                                    )
                                }
                            }
                        }
                    }
                }
            } else {
                Surface(
                    color = MaterialTheme.colorScheme.primaryContainer,
                ) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(16.dp)
                            .navigationBarsPadding(),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Default.CheckCircle,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.primary,
                            modifier = Modifier.size(22.dp),
                        )
                        Spacer(Modifier.width(10.dp))
                        Text(
                            "Message sent! You\u2019ll be notified when they reply.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onPrimaryContainer,
                        )
                    }
                }
            }
        },
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .verticalScroll(rememberScrollState()),
        ) {
            // Compact photo - user already saw it in the deck
            CompactPhoto(message = msg)

            // Item details immediately visible
            ItemDetails(message = msg, poster = poster)

            // Other items from this person
            if (otherPosts.isNotEmpty()) {
                OtherItemsFromPoster(
                    posterName = poster?.displayname ?: poster?.firstname ?: "this person",
                    posts = otherPosts,
                    onItemClick = { id ->
                        onMessageClick?.invoke(id)
                    },
                )
            }
        }
    }
}

@Composable
private fun CompactPhoto(message: MessageSummary) {
    val attachments = message.messageAttachments ?: emptyList()
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"

    if (attachments.isNotEmpty()) {
        val pagerState = rememberPagerState(pageCount = { attachments.size })

        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(250.dp),
        ) {
            HorizontalPager(
                state = pagerState,
                modifier = Modifier.fillMaxSize(),
            ) { page ->
                var scale by remember { mutableFloatStateOf(1f) }
                var offsetX by remember { mutableFloatStateOf(0f) }
                var offsetY by remember { mutableFloatStateOf(0f) }

                AsyncImage(
                    model = attachments[page].path,
                    contentDescription = title,
                    modifier = Modifier
                        .fillMaxSize()
                        .pointerInput(Unit) {
                            detectTransformGestures { _, pan, zoom, _ ->
                                scale = (scale * zoom).coerceIn(1f, 4f)
                                if (scale > 1f) {
                                    offsetX += pan.x
                                    offsetY += pan.y
                                } else {
                                    offsetX = 0f
                                    offsetY = 0f
                                }
                            }
                        }
                        .graphicsLayer {
                            scaleX = scale
                            scaleY = scale
                            translationX = if (scale > 1f) offsetX else 0f
                            translationY = if (scale > 1f) offsetY else 0f
                        },
                    contentScale = ContentScale.Crop,
                )
            }

            // Page indicator
            if (attachments.size > 1) {
                Row(
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .padding(bottom = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    repeat(attachments.size) { index ->
                        val isCurrent = pagerState.currentPage == index
                        Box(
                            modifier = Modifier
                                .size(
                                    width = if (isCurrent) 20.dp else 8.dp,
                                    height = 8.dp,
                                )
                                .clip(CircleShape)
                                .background(
                                    if (isCurrent) Color.White
                                    else Color.White.copy(alpha = 0.45f),
                                ),
                        )
                    }
                }

                // Photo count badge
                Surface(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .padding(8.dp),
                    shape = RoundedCornerShape(12.dp),
                    color = Color.Black.copy(alpha = 0.5f),
                ) {
                    Text(
                        "${pagerState.currentPage + 1}/${attachments.size}",
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                        style = MaterialTheme.typography.labelSmall,
                        color = Color.White,
                    )
                }
            }
        }
    } else {
        // No photo - question mark placeholder, compact height
        QuestionMarkPlaceholder(
            isOffer = isOffer,
            modifier = Modifier
                .fillMaxWidth()
                .height(160.dp),
        )
    }
}

@Composable
private fun ItemDetails(
    message: MessageSummary,
    poster: User? = null,
) {
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"
    val location = message.location?.areaname
        ?: extractLocation(message.subject)
    val timeStr = message.arrival ?: message.date
    val posterName = poster?.displayname ?: poster?.firstname

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 20.dp, vertical = 16.dp),
    ) {
        // Type badge + time
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Surface(
                shape = RoundedCornerShape(4.dp),
                color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
            ) {
                Text(
                    text = if (isOffer) "OFFER" else "WANTED",
                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
                    style = MaterialTheme.typography.labelMedium,
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                )
            }
            if (timeStr != null) {
                Text(
                    formatTimeAgo(timeStr),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        Spacer(Modifier.height(10.dp))

        // Title
        Text(
            text = title,
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
        )

        // Location
        if (location != null) {
            Spacer(Modifier.height(6.dp))
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Icon(
                    Icons.Default.LocationOn,
                    contentDescription = null,
                    modifier = Modifier.size(15.dp),
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = location,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        // Qualitative interest level instead of raw count
        Spacer(Modifier.height(6.dp))
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Icon(
                Icons.Default.Favorite,
                contentDescription = null,
                modifier = Modifier.size(15.dp),
                tint = if (message.replycount > 0) Color(0xFF008040) else MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                qualitativeInterest(message.replycount),
                style = MaterialTheme.typography.bodyMedium,
                color = if (message.replycount > 0) Color(0xFF008040) else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        // Description
        if (!message.textbody.isNullOrBlank()) {
            Spacer(Modifier.height(12.dp))
            Text(
                text = message.textbody!!,
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurface,
            )
        }

        Spacer(Modifier.height(16.dp))
        HorizontalDivider()
        Spacer(Modifier.height(12.dp))

        // Poster info
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            val profileUrl = poster?.profile?.path ?: poster?.profile?.paththumb
            if (profileUrl != null) {
                AsyncImage(
                    model = profileUrl,
                    contentDescription = posterName,
                    modifier = Modifier
                        .size(44.dp)
                        .clip(CircleShape),
                    contentScale = ContentScale.Crop,
                )
            } else {
                Box(
                    modifier = Modifier
                        .size(44.dp)
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.secondaryContainer),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        (posterName ?: "F").first().uppercase(),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onSecondaryContainer,
                    )
                }
            }

            Column(modifier = Modifier.weight(1f)) {
                Text(
                    posterName ?: "A Freegler",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                )
                val addedStr = poster?.added
                if (addedStr != null) {
                    val year = addedStr.take(4)
                    Text(
                        "Freegler since $year",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            // Ratings
            val upRatings = poster?.info?.let { it.offers + it.collected } ?: 0
            if (upRatings > 0) {
                Surface(
                    shape = RoundedCornerShape(8.dp),
                    color = MaterialTheme.colorScheme.primaryContainer,
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        Icon(
                            Icons.Default.ThumbUp,
                            contentDescription = null,
                            modifier = Modifier.size(14.dp),
                            tint = MaterialTheme.colorScheme.primary,
                        )
                        Text(
                            "$upRatings",
                            style = MaterialTheme.typography.labelMedium,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                }
            }
        }

        // About me
        if (!poster?.aboutme?.text.isNullOrBlank()) {
            Spacer(Modifier.height(8.dp))
            Text(
                poster!!.aboutme!!.text!!,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 3,
            )
        }

        // Stats row
        if (poster?.info != null) {
            Spacer(Modifier.height(8.dp))
            Row(
                horizontalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                val info = poster.info!!
                if (info.offers > 0) {
                    Text(
                        "${info.offers} given away",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                if (info.collected > 0) {
                    Text(
                        "${info.collected} collected",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

/** "Other items from this person" horizontal scrollable row. */
@Composable
private fun OtherItemsFromPoster(
    posterName: String,
    posts: List<MessageSummary>,
    onItemClick: (Long) -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 8.dp),
    ) {
        HorizontalDivider(modifier = Modifier.padding(horizontal = 20.dp))
        Spacer(Modifier.height(12.dp))

        Text(
            "Other items from $posterName",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.padding(horizontal = 20.dp),
        )
        Spacer(Modifier.height(8.dp))

        LazyRow(
            contentPadding = PaddingValues(horizontal = 20.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            items(posts, key = { it.id }) { post ->
                OtherItemCard(
                    message = post,
                    onClick = { onItemClick(post.id) },
                )
            }
        }

        Spacer(Modifier.height(12.dp))
    }
}

@Composable
private fun OtherItemCard(
    message: MessageSummary,
    onClick: () -> Unit,
) {
    val imageUrl = message.messageAttachments?.firstOrNull()?.paththumb
        ?: message.messageAttachments?.firstOrNull()?.path
    val title = cleanTitle(message.subject ?: "Item")
    val isOffer = message.type == "Offer"

    Card(
        onClick = onClick,
        modifier = Modifier.width(100.dp),
        shape = RoundedCornerShape(10.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column {
            if (imageUrl != null) {
                AsyncImage(
                    model = imageUrl,
                    contentDescription = title,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(60.dp)
                        .clip(RoundedCornerShape(topStart = 10.dp, topEnd = 10.dp)),
                    contentScale = ContentScale.Crop,
                )
            } else {
                QuestionMarkPlaceholder(
                    isOffer = isOffer,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(60.dp),
                )
            }
            Text(
                title,
                modifier = Modifier.padding(6.dp),
                style = MaterialTheme.typography.labelSmall,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}
