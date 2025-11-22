<?php

namespace App\Http\Controllers;

use App\Actions\Ai\EditAiMessage;
use App\Actions\Telegram\SendAiAnswerMessage;
use App\Actions\Telegram\SendContactMessage;
use App\Actions\Telegram\SendStartMessage;
use App\DTOs\TelegramUpdateDto;
use App\Models\BotUser;
use App\Services\Tg\TgEditMessageService;
use App\Services\Tg\TgMessageService;
use App\Services\TgExternal\TgExternalEditService;
use App\Services\TgExternal\TgExternalMessageService;
use App\Services\TgTopicService;
use App\Services\TgVk\TgVkEditService;
use App\Services\TgVk\TgVkMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramBotController
{
    private TelegramUpdateDto $dataHook;

    protected ?string $platform;

    public function __construct(Request $request)
    {
        $dataHook = TelegramUpdateDto::fromRequest($request);
        $this->dataHook = !empty($dataHook) ? $dataHook : die();

        if ($this->dataHook->typeSource === 'private') {
            $this->platform = 'telegram';
        } else {
            // Для supergroup сообщений без топика не обрабатываем
            if ($this->dataHook->typeSource === 'supergroup' && empty($this->dataHook->messageThreadId)) {
                Log::debug('TelegramBotController: Сообщение из supergroup без топика, пропускаем', [
                    'chatId' => $this->dataHook->chatId,
                    'text' => $this->dataHook->text,
                ]);
                $this->platform = null;
            } else {
                // Безопасный вызов с проверкой на null
                $this->platform = BotUser::getPlatformByTopicId($this->dataHook->messageThreadId);
                
                // Логирование для диагностики supergroup сообщений
                if ($this->dataHook->typeSource === 'supergroup') {
                    Log::debug('TelegramBotController: Обработка supergroup сообщения', [
                        'messageThreadId' => $this->dataHook->messageThreadId,
                        'platform' => $this->platform,
                        'chatId' => $this->dataHook->chatId,
                        'isBot' => $this->dataHook->isBot,
                        'text' => $this->dataHook->text,
                    ]);
                }
            }
        }
    }

    /**
     * Check type source
     *
     * @return bool
     */
    protected function isSupergroup(): bool
    {
        return $this->dataHook->typeSource === 'supergroup';
    }

    /**
     * Check message
     *
     * @return void
     */
    protected function checkBotQuery(): void
    {
        if ($this->dataHook->pinnedMessageStatus) {
            die();
        }
    }

    /**
     * @return void
     *
     * @throws \Exception
     */
    public function bot_query(): void
    {
        $this->checkBotQuery();
        
        // Пропускаем сообщения без platform (например, supergroup без топика)
        if (empty($this->platform) && $this->dataHook->typeSource === 'supergroup') {
            Log::debug('TelegramBotController: Пропускаем сообщение без platform');
            return;
        }
        
        if (!$this->dataHook->isBot) {
            switch ($this->platform) {
                case 'telegram':
                    $this->controllerPlatformTg();
                    break;

                case 'vk':
                    $this->controllerPlatformVk();
                    break;

                default:
                    // Если platform не определена для supergroup, логируем и пытаемся обработать как telegram
                    if ($this->dataHook->typeSource === 'supergroup') {
                        Log::warning('TelegramBotController: platform не определена для supergroup, пытаемся обработать как telegram', [
                            'messageThreadId' => $this->dataHook->messageThreadId,
                            'chatId' => $this->dataHook->chatId,
                        ]);
                        $this->controllerPlatformTg();
                    } else {
                        $this->controllerExternalPlatform();
                    }
                    break;
            }
        } else {
            if ($this->dataHook->editedTopicStatus) {
                TgTopicService::deleteNoteInTopic($this->dataHook->messageId);
            }
        }
    }

    /**
     * Controller tg message
     *
     * @return void
     */
    private function controllerPlatformTg(): void
    {
        if ($this->dataHook->aiTechMessage) {
            if (str_contains($this->dataHook->text, 'ai_message_edit_')) {
                (new EditAiMessage())->execute($this->dataHook);
            }
        } else {
            switch ($this->dataHook->typeQuery) {
                case 'message':
                    if ($this->dataHook->text === '/contact' && $this->isSupergroup()) {
                        (new SendContactMessage())->executeByChatId($this->dataHook->chatId);
                    } elseif ($this->dataHook->text === '/start' && !$this->isSupergroup()) {
                        (new SendStartMessage())->execute($this->dataHook);
                    } elseif (str_contains($this->dataHook->text, '/ai_generate') && $this->isSupergroup()) {
                        (new SendAiAnswerMessage())->execute($this->dataHook);
                    } else {
                        try {
                            (new TgMessageService($this->dataHook))->handleUpdate();
                        } catch (\Exception $e) {
                            Log::error('TelegramBotController: Ошибка обработки сообщения', [
                                'error' => $e->getMessage(),
                                'chat_id' => $this->dataHook->chatId,
                                'typeSource' => $this->dataHook->typeSource,
                                'text' => $this->dataHook->text,
                            ]);
                        }
                    }
                    break;

                case 'edited_message':
                    (new TgEditMessageService($this->dataHook))->handleUpdate();
                    break;

                default:
                    throw new \Exception("Неизвестный тип события: {$this->dataHook->typeQuery}");
            }
        }
    }

    /**
     * Controller vk message
     *
     * @return void
     */
    private function controllerPlatformVk(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgVkMessageService($this->dataHook))->handleUpdate();
                break;

            case 'edited_message':
                (new TgVkEditService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Неизвестный тип события: {$this->dataHook->typeQuery}");
        }
    }

    /**
     * Controller external message
     *
     * @return void
     */
    private function controllerExternalPlatform(): void
    {
        switch ($this->dataHook->typeQuery) {
            case 'message':
                (new TgExternalMessageService($this->dataHook))->handleUpdate();
                break;

            case 'edited_message':
                (new TgExternalEditService($this->dataHook))->handleUpdate();
                break;

            default:
                throw new \Exception("Неизвестный тип события: {$this->dataHook->typeQuery}");
        }
    }
}
