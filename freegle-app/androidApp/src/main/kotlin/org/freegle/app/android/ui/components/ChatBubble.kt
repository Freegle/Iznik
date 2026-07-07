package org.freegle.app.android.ui.components

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import org.freegle.app.model.ChatMessage

@Composable
fun ChatBubble(
    message: ChatMessage,
    isMe: Boolean,
    showTimestamp: Boolean = true,
) {
    val alignment = if (isMe) Alignment.End else Alignment.Start
    val bgColor = if (isMe) MaterialTheme.colorScheme.primaryContainer
    else MaterialTheme.colorScheme.surfaceVariant
    val textColor = if (isMe) MaterialTheme.colorScheme.onPrimaryContainer
    else MaterialTheme.colorScheme.onSurfaceVariant

    // Asymmetric bubble shapes - real chat apps do this
    val bubbleShape = if (isMe) {
        RoundedCornerShape(topStart = 16.dp, topEnd = 16.dp, bottomStart = 16.dp, bottomEnd = 4.dp)
    } else {
        RoundedCornerShape(topStart = 4.dp, topEnd = 16.dp, bottomStart = 16.dp, bottomEnd = 16.dp)
    }

    Column(
        modifier = Modifier.fillMaxWidth(),
        horizontalAlignment = alignment,
    ) {
        Surface(
            shape = bubbleShape,
            color = bgColor,
            modifier = Modifier.widthIn(max = 280.dp),
        ) {
            Column(modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
                Text(
                    text = message.message ?: "",
                    color = textColor,
                    style = MaterialTheme.typography.bodyMedium,
                )
                if (showTimestamp) {
                    Spacer(Modifier.height(2.dp))
                    Text(
                        text = formatTimeAgo(message.date ?: ""),
                        style = MaterialTheme.typography.labelSmall,
                        color = textColor.copy(alpha = 0.55f),
                        modifier = Modifier.align(if (isMe) Alignment.End else Alignment.Start),
                    )
                }
            }
        }
    }
}
