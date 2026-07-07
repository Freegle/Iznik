package org.freegle.app.android.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Forum
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import kotlinx.coroutines.launch
import org.freegle.app.android.ui.components.formatTimeAgo
import org.freegle.app.model.ChatRoom
import org.freegle.app.repository.ChatRepository
import org.koin.compose.koinInject

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatListScreen(
    onChatClick: (Long) -> Unit,
    onBrowseClick: () -> Unit = {},
    chatRepository: ChatRepository = koinInject(),
) {
    val chatRooms by chatRepository.chatRooms.collectAsState()
    val isLoading by chatRepository.isLoading.collectAsState()
    val error by chatRepository.error.collectAsState()
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        chatRepository.loadChatRooms()
    }

    Column(modifier = Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        // Header
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 20.dp, vertical = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "Messages",
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
            )
        }

        // Error banner
        if (error != null) {
            Surface(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
                shape = RoundedCornerShape(12.dp),
                color = MaterialTheme.colorScheme.errorContainer,
            ) {
                Row(
                    modifier = Modifier.padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        error ?: "",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onErrorContainer,
                        modifier = Modifier.weight(1f),
                    )
                    TextButton(onClick = { scope.launch { chatRepository.loadChatRooms() } }) {
                        Text("Retry", style = MaterialTheme.typography.labelSmall)
                    }
                }
            }
        }

        PullToRefreshBox(
            isRefreshing = isLoading,
            onRefresh = { scope.launch { chatRepository.loadChatRooms() } },
            modifier = Modifier.fillMaxSize(),
        ) {
            if (chatRooms.isEmpty() && !isLoading) {
                EmptyChatState(onBrowseClick = onBrowseClick)
            } else {
                LazyColumn(modifier = Modifier.fillMaxSize()) {
                    // Stories row (active / recent chats)
                    if (chatRooms.isNotEmpty()) {
                        item {
                            StoriesRow(
                                chatRooms = chatRooms.take(8),
                                onChatClick = onChatClick,
                            )
                        }
                        item {
                            HorizontalDivider(modifier = Modifier.padding(vertical = 4.dp))
                        }
                    }

                    // Conversation list
                    items(chatRooms) { chat ->
                        ChatListItem(chat = chat, onClick = { onChatClick(chat.id) })
                    }
                }
            }
        }
    }
}

@Composable
private fun StoriesRow(
    chatRooms: List<ChatRoom>,
    onChatClick: (Long) -> Unit,
) {
    Column {
        Text(
            "Active",
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp),
            fontWeight = FontWeight.SemiBold,
        )
        LazyRow(
            contentPadding = PaddingValues(horizontal = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(16.dp),
            modifier = Modifier.padding(bottom = 12.dp),
        ) {
            items(chatRooms) { chat ->
                StoryAvatar(
                    chat = chat,
                    onClick = { onChatClick(chat.id) },
                )
            }
        }
    }
}

@Composable
private fun StoryAvatar(
    chat: ChatRoom,
    onClick: () -> Unit,
) {
    val hasUnread = chat.unseen > 0
    val initial = (chat.name ?: "?").firstOrNull()?.uppercase() ?: "?"
    val avatarColors = listOf(
        listOf(Color(0xFF008040), Color(0xFF4CD681)),
        listOf(Color(0xFF1565C0), Color(0xFF42A5F5)),
        listOf(Color(0xFF2196F3), Color(0xFF64B5F6)),
        listOf(Color(0xFF9C27B0), Color(0xFFCE93D8)),
        listOf(Color(0xFFFF5722), Color(0xFFFF8A65)),
    )
    val colorPair = avatarColors[(chat.id % avatarColors.size).toInt()]

    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = Modifier
            .clickable(onClick = onClick)
            .width(72.dp),
    ) {
        // Story ring + avatar
        Box(
            modifier = Modifier.size(64.dp),
            contentAlignment = Alignment.Center,
        ) {
            // Activity ring
            if (hasUnread) {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .clip(CircleShape)
                        .background(
                            Brush.sweepGradient(colors = colorPair),
                        ),
                )
                // Inner padding gap
                Box(
                    modifier = Modifier
                        .size(58.dp)
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.background),
                    contentAlignment = Alignment.Center,
                ) {
                    AvatarContent(chat = chat, size = 52, initial = initial)
                }
            } else {
                // No ring - just muted border
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.surfaceVariant),
                    contentAlignment = Alignment.Center,
                ) {
                    AvatarContent(chat = chat, size = 56, initial = initial)
                }
            }

            // Unread badge
            if (hasUnread && chat.unseen <= 9) {
                Box(
                    modifier = Modifier
                        .align(Alignment.BottomEnd)
                        .size(20.dp)
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.primary),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        "${chat.unseen}",
                        style = MaterialTheme.typography.labelSmall,
                        color = Color.White,
                        fontSize = 10.sp,
                    )
                }
            }
        }

        Spacer(Modifier.height(4.dp))

        Text(
            text = chat.name?.split(" ")?.firstOrNull() ?: "Chat",
            style = MaterialTheme.typography.labelSmall,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            fontWeight = if (hasUnread) FontWeight.Bold else FontWeight.Normal,
        )
    }
}

@Composable
private fun AvatarContent(
    chat: ChatRoom,
    size: Int,
    initial: String,
) {
    if (chat.icon != null) {
        AsyncImage(
            model = chat.icon,
            contentDescription = chat.name,
            modifier = Modifier
                .size(size.dp)
                .clip(CircleShape),
        )
    } else {
        val avatarColors = listOf(
            MaterialTheme.colorScheme.primaryContainer,
            MaterialTheme.colorScheme.secondaryContainer,
            MaterialTheme.colorScheme.tertiaryContainer,
        )
        val colorIndex = (chat.id % avatarColors.size).toInt()
        Box(
            modifier = Modifier
                .size(size.dp)
                .clip(CircleShape)
                .background(avatarColors[colorIndex]),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                text = initial,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

@Composable
private fun ChatListItem(
    chat: ChatRoom,
    onClick: () -> Unit,
) {
    val hasUnread = chat.unseen > 0

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .background(
                if (hasUnread) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.15f)
                else Color.Transparent,
            )
            .padding(horizontal = 20.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // Avatar
        val initial = (chat.name ?: "?").firstOrNull()?.uppercase() ?: "?"
        AvatarContent(chat = chat, size = 52, initial = initial)

        Spacer(Modifier.width(14.dp))

        // Content
        Column(modifier = Modifier.weight(1f)) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = chat.name ?: "Chat",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = if (hasUnread) FontWeight.Bold else FontWeight.SemiBold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                Spacer(Modifier.width(8.dp))
                chat.lastdate?.let { date ->
                    Text(
                        text = formatRelativeTime(date),
                        style = MaterialTheme.typography.labelSmall,
                        color = if (hasUnread) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.onSurfaceVariant,
                        fontWeight = if (hasUnread) FontWeight.SemiBold else FontWeight.Normal,
                    )
                }
            }
            Spacer(Modifier.height(2.dp))
            Text(
                text = chat.snippet ?: "",
                style = MaterialTheme.typography.bodyMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                color = if (hasUnread) MaterialTheme.colorScheme.onSurface
                else MaterialTheme.colorScheme.onSurfaceVariant,
                fontWeight = if (hasUnread) FontWeight.Medium else FontWeight.Normal,
            )
        }

        if (hasUnread && chat.unseen > 9) {
            Spacer(Modifier.width(8.dp))
            Badge {
                Text("${chat.unseen.coerceAtMost(99)}")
            }
        }
    }

    HorizontalDivider(
        modifier = Modifier.padding(start = 86.dp, end = 20.dp),
        color = MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.4f),
    )
}

@Composable
private fun EmptyChatState(onBrowseClick: () -> Unit) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(40.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(100.dp)
                    .background(MaterialTheme.colorScheme.primaryContainer, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Outlined.Forum,
                    contentDescription = null,
                    modifier = Modifier.size(52.dp),
                    tint = MaterialTheme.colorScheme.primary,
                )
            }
            Spacer(Modifier.height(24.dp))
            Text(
                "No conversations yet",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(8.dp))
            Text(
                "When you reply to someone's item — or they reply to yours — your chats appear here.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(28.dp))
            Button(
                onClick = onBrowseClick,
                shape = RoundedCornerShape(16.dp),
            ) {
                Text("Browse free items")
            }
        }
    }
}

private fun formatRelativeTime(dateStr: String): String {
    return try {
        val ago = formatTimeAgo(dateStr)
        if (ago.isEmpty()) dateStr.take(5) else ago
    } catch (_: Exception) {
        dateStr.take(5)
    }
}
