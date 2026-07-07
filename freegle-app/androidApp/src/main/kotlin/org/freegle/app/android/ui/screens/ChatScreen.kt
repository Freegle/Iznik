package org.freegle.app.android.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.freegle.app.android.ui.components.ChatBubble
import org.freegle.app.android.ui.components.cleanTitle
import org.freegle.app.model.MessageSummary
import org.freegle.app.repository.ChatRepository
import org.freegle.app.repository.MessageRepository
import org.freegle.app.repository.UserRepository
import org.koin.compose.koinInject
import androidx.compose.foundation.clickable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import coil3.compose.AsyncImage

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatScreen(
    chatId: Long,
    onBack: () -> Unit,
    chatRepository: ChatRepository = koinInject(),
    userRepository: UserRepository = koinInject(),
    messageRepository: MessageRepository = koinInject(),
) {
    val messages by chatRepository.currentMessages.collectAsState()
    val isLoading by chatRepository.isLoading.collectAsState()
    val chatRooms by chatRepository.chatRooms.collectAsState()
    val currentUser by userRepository.currentUser.collectAsState()
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()

    var messageText by remember { mutableStateOf("") }
    var isSending by remember { mutableStateOf(false) }
    var sendError by remember { mutableStateOf<String?>(null) }

    // Item context: find the referenced message from chat messages
    var contextItem by remember { mutableStateOf<MessageSummary?>(null) }

    // Get chat room info for the title and item context
    val chatRoom = chatRooms.find { it.id == chatId }
    val chatName = chatRoom?.name ?: "Chat"

    LaunchedEffect(chatId) {
        chatRepository.loadMessages(chatId)
    }

    // Load the referenced item for context header
    LaunchedEffect(messages) {
        if (contextItem == null) {
            val refMsgId = messages.firstNotNullOfOrNull { it.refmsgid }
            if (refMsgId != null) {
                contextItem = messageRepository.getMessageDetail(refMsgId)
            }
        }
    }

    LaunchedEffect(messages.size) {
        if (messages.isNotEmpty()) {
            listState.animateScrollToItem(messages.size - 1)
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            text = chatName,
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.SemiBold,
                        )
                        if (chatRoom?.snippet != null) {
                            Text(
                                text = "Active conversation",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding),
        ) {
            // Item context header - shows which item is being discussed
            contextItem?.let { item ->
                val imageUrl = item.messageAttachments?.firstOrNull()?.paththumb
                    ?: item.messageAttachments?.firstOrNull()?.path
                val title = cleanTitle(item.subject ?: "Item")
                val isOffer = item.type == "Offer"
                Surface(
                    color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (imageUrl != null) {
                            AsyncImage(
                                model = imageUrl,
                                contentDescription = title,
                                modifier = Modifier
                                    .size(44.dp)
                                    .clip(RoundedCornerShape(8.dp)),
                                contentScale = ContentScale.Crop,
                            )
                        } else {
                            Surface(
                                modifier = Modifier.size(44.dp),
                                shape = RoundedCornerShape(8.dp),
                                color = if (isOffer) Color(0xFF008040).copy(alpha = 0.15f)
                                else Color(0xFF1565C0).copy(alpha = 0.15f),
                            ) {}
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(
                                text = title,
                                style = MaterialTheme.typography.titleSmall,
                                fontWeight = FontWeight.SemiBold,
                                maxLines = 1,
                            )
                            Text(
                                text = if (isOffer) "Free item" else "Wanted",
                                style = MaterialTheme.typography.bodySmall,
                                color = if (isOffer) Color(0xFF008040) else Color(0xFF1565C0),
                            )
                        }
                    }
                }
                HorizontalDivider()
            }

            // Messages list
            LazyColumn(
                state = listState,
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                itemsIndexed(messages) { idx, message ->
                    val isMe = message.userid == (currentUser?.id ?: 0L)
                    // Only show timestamp on last message or when sender changes
                    val isLastFromSender = idx == messages.lastIndex ||
                        messages[idx + 1].userid != message.userid
                    ChatBubble(
                        message = message,
                        isMe = isMe,
                        showTimestamp = isLastFromSender,
                    )
                    // Reduced spacing between consecutive messages from same person
                    if (!isLastFromSender) {
                        Spacer(Modifier.height(2.dp))
                    }
                }
            }

            // Quick reply chips (only when no text typed)
            if (messageText.isEmpty() && messages.isEmpty()) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 12.dp, vertical = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    SuggestionChip(
                        onClick = { messageText = "Is this still available?" },
                        label = { Text("Still available?") },
                    )
                    SuggestionChip(
                        onClick = { messageText = "I'd love to have this, please!" },
                        label = { Text("I'd love this!") },
                    )
                }
            }

            // Send error
            if (sendError != null) {
                Surface(
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp),
                    shape = RoundedCornerShape(8.dp),
                    color = MaterialTheme.colorScheme.errorContainer,
                ) {
                    Text(
                        sendError ?: "",
                        modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onErrorContainer,
                    )
                }
            }

            // Input bar
            HorizontalDivider()
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedTextField(
                    value = messageText,
                    onValueChange = { messageText = it },
                    modifier = Modifier.weight(1f),
                    placeholder = { Text("Type a message...") },
                    maxLines = 4,
                    shape = MaterialTheme.shapes.extraLarge,
                )
                Spacer(Modifier.width(8.dp))
                FilledIconButton(
                    onClick = {
                        if (messageText.isNotBlank() && !isSending) {
                            val text = messageText
                            messageText = ""
                            isSending = true
                            sendError = null
                            scope.launch {
                                try {
                                    val success = chatRepository.sendMessage(chatId, text)
                                    if (!success) {
                                        messageText = text // Restore the message
                                        sendError = "Couldn\u2019t send. Tap to retry."
                                    }
                                } catch (_: Exception) {
                                    messageText = text
                                    sendError = "No connection. Check your internet."
                                }
                                isSending = false
                            }
                        }
                    },
                    enabled = messageText.isNotBlank() && !isSending,
                ) {
                    Icon(Icons.AutoMirrored.Filled.Send, contentDescription = "Send")
                }
            }
        }
    }
}
