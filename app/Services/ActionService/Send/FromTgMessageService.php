<?php

namespace App\Services\ActionService\Send;

use App\DTOs\TelegramUpdateDto;
use App\DTOs\TGTextMessageDto;
use App\Models\BotUser;
use App\Services\TgTopicService;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Exception;

/**
 * Class FromTgMessageService
 * Класс для работы с сообщениями из TG в "Источник"
 */
abstract class FromTgMessageService extends TemplateMessageService
{
    public function __construct(TelegramUpdateDto $update)
    {
        $this->update = $update;
        $this->tgTopicService = new TgTopicService();
        $this->botUser = BotUser::getTelegramUserData($this->update);

        if (empty($this->botUser)) {
            Log::error('FromTgMessageService: Пользователь не найден', [
                'chat_id' => $update->chatId,
                'typeSource' => $update->typeSource,
                'messageThreadId' => $update->messageThreadId,
                'text' => $update->text,
            ]);
            throw new Exception('Пользователя не существует!');
        }

        switch ($update->typeSource) {
            case 'private':
                $this->typeMessage = 'incoming';

                $groupId = config('traffic_source.settings.telegram.group_id');
                if (empty($groupId)) {
                    Log::error('FromTgMessageService: group_id не настроен в конфиге');
                    throw new Exception('group_id не настроен в конфиге!');
                }
                
                if (empty($this->botUser->topic_id)) {
                    Log::warning('FromTgMessageService: topic_id пустой, пытаемся создать новый топик', [
                        'bot_user_id' => $this->botUser->id,
                        'chat_id' => $this->botUser->chat_id,
                    ]);
                    $this->botUser->saveNewTopic();
                }
                
                $queryParams = [
                    'chat_id' => $groupId,
                    'message_thread_id' => $this->botUser->topic_id,
                ];
                break;

            case 'supergroup':
                $this->typeMessage = 'outgoing';
                $queryParams = [
                    'chat_id' => $this->botUser->chat_id,
                ];
                break;

            default:
                throw new Exception('Данный тип запроса не поддерживается!');
        }

        $queryParams['methodQuery'] = 'sendMessage';
        $queryParams['typeSource'] = $update->typeSource;
        $this->messageParamsDTO = TGTextMessageDto::from($queryParams);
        
        Log::debug('FromTgMessageService: Параметры отправки сообщения', [
            'typeSource' => $update->typeSource,
            'queryParams' => $queryParams,
            'topic_id' => $this->botUser->topic_id ?? null,
        ]);
    }

    /**
     * @return mixed
     *
     * @throws \Exception
     */
    abstract public function handleUpdate(): mixed;

    /**
     * Отправка изображения
     *
     * @return mixed
     */
    abstract protected function sendPhoto(): mixed;

    /**
     * Отправка документа
     *
     * @return mixed
     */
    abstract protected function sendDocument(): mixed;

    /**
     * Отправка геолокации
     *
     * @return mixed
     */
    abstract protected function sendLocation(): mixed;

    /**
     * Отправка голосового сообщения
     *
     * @return mixed
     */
    abstract protected function sendVoice(): mixed;

    /**
     * Отправка стикеров
     *
     * @return mixed
     */
    abstract protected function sendSticker(): mixed;

    /**
     * Отправка видео-сообщения
     *
     * @return mixed
     */
    abstract protected function sendVideoNote(): mixed;

    /**
     * Отправка контакта
     *
     * @return mixed
     */
    abstract protected function sendContact(): mixed;

    /**
     * Отправка текстового сообщения
     *
     * @return mixed
     */
    abstract protected function sendMessage(): mixed;

    /**
     * Сохранения сообщения
     *
     * @param $resultQuery
     *
     * @return mixed
     */
    abstract protected function saveMessage($resultQuery): mixed;
}
