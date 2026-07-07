<?php
// Test chat notification email with full features (previous messages + outcome buttons)

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

// Mock user class with proper ID handling
class MockUserFull extends User
{
    protected $mockEmail = null;
    protected $mockDisplayName = null;
    public $exists = false;  // Set false so email tracking doesn't try to use fake user ID

    public static function createMock(int $id, string $fullname, ?string $email = null, ?string $aboutme = null): self
    {
        $user = new self();
        $user->id = $id;
        $user->fullname = $fullname;
        $user->firstname = explode(' ', $fullname)[0];
        $user->aboutme = $aboutme;
        $user->mockEmail = $email;
        $user->mockDisplayName = $fullname;
        return $user;
    }

    public function getEmailPreferredAttribute(): ?string
    {
        return $this->mockEmail;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->mockDisplayName ?? $this->fullname ?? 'Freegle User';
    }
}

// Create mock recipient user (the poster of the OFFER)
$recipient = MockUserFull::createMock(
    12345,
    'Jane Smith',
    'test@test.com'
);

// Create mock sender user (interested party)
$sender = MockUserFull::createMock(
    67890,
    'John Doe',
    'john@example.com',
    'I love giving away things I no longer need. Been a Freegle member for 5 years!'
);

// Create mock chat room
$chatRoom = new ChatRoom();
$chatRoom->id = 999;
$chatRoom->user1 = 12345;
$chatRoom->user2 = 67890;
$chatRoom->chattype = ChatRoom::TYPE_USER2USER;

// Create mock referenced message (an OFFER) - fromuser must match recipient.id for outcome buttons
$refMessage = new Message();
$refMessage->id = 1001;
$refMessage->subject = 'OFFER: Vintage Wooden Bookshelf - Great condition';
$refMessage->type = Message::TYPE_OFFER;
$refMessage->fromuser = 12345; // This matches recipient.id so outcome buttons will show

// Create mock new messages (from sender to recipient)
$messages = collect([
    (object)[
        'id' => 2001,
        'chatid' => 999,
        'userid' => 67890,
        'type' => ChatMessage::TYPE_INTERESTED,
        'message' => "Hi! I'm really interested in your bookshelf. Would it fit in a small car? I have a Ford Focus.",
        'date' => now()->subMinutes(10),
        'replyexpected' => true,  // This triggers RSVP banner
        'imageid' => null,
        'refmsgid' => 1001,
        'user' => $sender,
        'refMessage' => $refMessage,
    ],
    (object)[
        'id' => 2002,
        'chatid' => 999,
        'userid' => 67890,
        'type' => ChatMessage::TYPE_DEFAULT,
        'message' => "Also, is it still available? I could collect this weekend - Saturday afternoon works best for me.",
        'date' => now()->subMinutes(5),
        'replyexpected' => false,
        'imageid' => null,
        'refmsgid' => null,
        'user' => $sender,
        'refMessage' => null,
    ],
]);

// Convert to ChatMessage-like objects
$preparedMessages = $messages->map(function ($msg) {
    $cm = new ChatMessage();
    $cm->id = $msg->id;
    $cm->chatid = $msg->chatid;
    $cm->userid = $msg->userid;
    $cm->type = $msg->type;
    $cm->message = $msg->message;
    $cm->date = $msg->date;
    $cm->replyexpected = $msg->replyexpected;
    $cm->imageid = $msg->imageid;
    $cm->refmsgid = $msg->refmsgid;

    $cm->setRelation('user', $msg->user);
    if ($msg->refMessage) {
        $cm->setRelation('refMessage', $msg->refMessage);
    }

    return $cm;
});

// Create mock previous messages for context
$previousMessages = collect([
    (object)[
        'id' => 1999,
        'chatid' => 999,
        'userid' => 12345,
        'type' => ChatMessage::TYPE_DEFAULT,
        'message' => "Thanks for your interest! Yes, it measures about 120cm x 80cm x 30cm deep. Should fit with the seats down.",
        'date' => now()->subHours(2),
        'replyexpected' => false,
        'imageid' => null,
        'user' => $recipient,
        'refMessage' => null,
    ],
    (object)[
        'id' => 1998,
        'chatid' => 999,
        'userid' => 67890,
        'type' => ChatMessage::TYPE_DEFAULT,
        'message' => "Perfect, that's really helpful. I'll measure my boot space later today.",
        'date' => now()->subHours(1)->subMinutes(30),
        'replyexpected' => false,
        'imageid' => null,
        'user' => $sender,
        'refMessage' => null,
    ],
]);

$preparedPreviousMessages = $previousMessages->map(function ($msg) {
    $cm = new ChatMessage();
    $cm->id = $msg->id;
    $cm->chatid = $msg->chatid;
    $cm->userid = $msg->userid;
    $cm->type = $msg->type;
    $cm->message = $msg->message;
    $cm->date = $msg->date;
    $cm->replyexpected = $msg->replyexpected;
    $cm->imageid = $msg->imageid;
    $cm->setRelation('user', $msg->user);
    return $cm;
});

echo "Creating ChatNotification email with full features...\n";
echo "- Previous messages: " . $preparedPreviousMessages->count() . "\n";
echo "- New messages: " . $preparedMessages->count() . "\n";
echo "- Recipient ID: " . $recipient->id . "\n";
echo "- RefMessage fromuser: " . $refMessage->fromuser . "\n";
echo "- Should show outcome buttons: " . ($recipient->id === $refMessage->fromuser ? 'YES' : 'NO') . "\n";

try {
    // Create the mailable
    $mail = new ChatNotification(
        $recipient,
        $sender,
        $chatRoom,
        $preparedMessages,
        ChatRoom::TYPE_USER2USER,
        $preparedPreviousMessages
    );

    echo "Subject: " . $mail->replySubject . "\n";
    echo "Sending to Mailhog...\n";

    // Send to Mailhog
    Mail::to('test@test.com')->send($mail);

    echo "Done! Check http://mailhog.localhost\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
