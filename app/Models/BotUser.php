<?php

namespace App\Models;

use App\Actions\Telegram\SendContactMessage;
use App\DTOs\TelegramUpdateDto;
use App\Logging\LokiLogger;
use App\Services\TgTopicService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

/**
 * @property int    $topic_id
 * @property int    $chat_id
 * @property string $platform
 * @property mixed  $aiCondition
 * @property mixed  $lastMessageManager
 * @property-read ExternalUser $externalUser
 */
class BotUser extends Model
{
    use HasFactory;

    protected $table = 'bot_users';

    protected $fillable = [
        'chat_id',
        'topic_id',
        'platform',
    ];

    /**
     * @return HasOne
     */
    public function externalUser(): HasOne
    {
        return $this->hasOne(ExternalUser::class, 'id', 'chat_id');
    }

    /**
     * @return HasOne
     */
    public function aiCondition(): HasOne
    {
        return $this->hasOne(AiCondition::class);
    }

    /**
     * @return HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'id', 'bot_user_id');
    }

    /**
     * @return HasOne
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * @return HasOne
     */
    public function lastMessageManager(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(['created_at' => 'max'], function ($q) {
            $q->where('message_type', 'outgoing');
        });
    }

    /**
     * Create new TG topic
     *
     * @return int|null
     */
    public function saveNewTopic(): ?int
    {
        try {
            $tgTopicService = new TgTopicService();
            $dataTopic = $tgTopicService->createNewTgTopic($this);

            $this->topic_id = $dataTopic->message_thread_id;
            $this->save();

            (new SendContactMessage())->executeByBotUser($this);

            return $dataTopic->message_thread_id;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get platform by chat id
     *
     * @param int $chatId
     *
     * @return string|null
     */
    public static function getPlatformByChatId(int $chatId): ?string
    {
        try {
            $botUser = self::select('platform')
                ->where('chat_id', $chatId)
                ->first();

            return $botUser ? $botUser->platform : null;
        } catch (\Exception $e) {
            (new LokiLogger())->sendBasicLog($e);
            return null;
        }
    }

    /**
     * Get platform by topic id
     *
     * @param int|null $messageThreadId
     *
     * @return string|null
     */
    public static function getPlatformByTopicId(?int $messageThreadId): ?string
    {
        try {
            if (empty($messageThreadId)) {
                Log::debug('BotUser::getPlatformByTopicId: messageThreadId пустой');
                return null;
            }

            $botUser = self::select('platform')
                ->where('topic_id', $messageThreadId)
                ->first();

            if (empty($botUser)) {
                Log::warning('BotUser::getPlatformByTopicId: Пользователь не найден по topic_id', [
                    'topic_id' => $messageThreadId,
                ]);
            }

            return $botUser->platform ?? null;
        } catch (\Exception $e) {
            Log::error('BotUser::getPlatformByTopicId: Ошибка', [
                'error' => $e->getMessage(),
                'topic_id' => $messageThreadId,
            ]);
            (new LokiLogger())->sendBasicLog($e);
            return null;
        }
    }

    /**
     * Geg user data
     *
     * @param TelegramUpdateDto $update
     *
     * @return BotUser|null
     */
    public static function getTelegramUserData(TelegramUpdateDto $update): ?BotUser
    {
        try {
            if ($update->typeSource === 'supergroup') {
                $botUser = self::where('topic_id', $update->messageThreadId)
                    ->with('externalUser')
                    ->first();
                
                if (empty($botUser)) {
                    Log::warning('BotUser::getTelegramUserData: Пользователь не найден для supergroup', [
                        'topic_id' => $update->messageThreadId,
                        'chat_id' => $update->chatId,
                    ]);
                }
            } elseif ($update->typeSource === 'private') {
                $botUser = self::firstOrCreate(
                    [
                        'chat_id' => $update->chatId,
                    ],
                    [
                        'platform' => 'telegram',
                    ]
                );
                if (empty($botUser->topic_id)) {
                    $botUser->saveNewTopic();
                }
            }

            return $botUser ?? null;
        } catch (\Exception $e) {
            Log::error('BotUser::getTelegramUserData: Ошибка', [
                'error' => $e->getMessage(),
                'chat_id' => $update->chatId,
                'typeSource' => $update->typeSource,
                'messageThreadId' => $update->messageThreadId,
            ]);
            return null;
        }
    }

    /**
     * @param string|int $chatId
     * @param string     $platform
     *
     * @return BotUser|null
     */
    public static function getUserByChatId(string|int $chatId, string $platform): ?BotUser
    {
        try {
            $botUser = self::firstOrCreate(
                [
                    'chat_id' => $chatId,
                ],
                [
                    'platform' => $platform,
                ]
            );
            if (empty($botUser->topic_id)) {
                $botUser->saveNewTopic();
            }

            return $botUser;
        } catch (\Exception $e) {
            return null;
        }
    }
}
