<?php

namespace SoftNova\Controllers;

use SoftNova\Core\TenantController;
use SoftNova\Services\AiService;

/**
 * Asistente IA "Seri" — OpenRouter (Gemini u otro vía .env)
 */
class TenantAiController extends TenantController
{
    public function index(): void
    {
        $ai = new AiService($this->db);
        $modules = [];
        if (\SoftNova\Core\is_standalone()) {
            $modules = ['ventas','inventario','prestamos','clientes','caja','cotizaciones','gastos','contabilidad','nomina','reportes','compras'];
        } else {
            $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                try {
                    $plan = \SoftNova\Core\Database::getInstance()->query(
                        "SELECT sp.modules
                         FROM tenants t
                         JOIN subscription_plans sp ON t.subscription_plan_id = sp.id
                         WHERE t.id = ? LIMIT 1",
                        [$tenantId]
                    )->fetch();
                    $decoded = json_decode((string)($plan['modules'] ?? '[]'), true);
                    if (is_array($decoded)) {
                        $modules = array_values(array_filter($decoded, 'is_string'));
                    }
                } catch (\Throwable $e) {
                    $modules = [];
                }
            }
        }

        $this->view('tenant.ai', $this->tenantViewData([
            'aiConfigured' => $ai->isConfigured(),
            'aiPersonality' => \SoftNova\Core\ai_personality($modules),
            'conversations' => $ai->conversations($this->currentUserId()),
        ]));
    }

    public function chat(): void
    {
        if (!$this->validateCsrfOrFail('/app/ia')) {
            return;
        }

        $action = (string)$this->request->get('action', '');
        if ($action === 'delete') {
            $this->deleteConversation();
            return;
        }

        $message = trim($this->request->post('message') ?? '');
        if ($message === '') {
            $this->json(['reply' => 'Por favor, escribe un mensaje para que pueda ayudarte.']);
            return;
        }

        if (mb_strlen($message) > 2000) {
            $this->json(['reply' => 'El mensaje es demasiado largo. Maximo 2000 caracteres.']);
            return;
        }

        $ai = new AiService($this->db);
        $userId = $this->currentUserId();
        $conversationId = (int)$this->request->post('conversation_id', 0);

        // Historial previo antes de agregar el mensaje actual.
        $history = [];
        if ($conversationId > 0 && $ai->conversationOwnedBy($conversationId, $userId)) {
            $history = $ai->messages($conversationId);
        } else {
            $conversationId = $ai->createConversation($userId, $message);
        }

        $ai->saveMessage($conversationId, 'user', $message);
        $ai->renameConversationFromMessage($conversationId, $message);

        $reply = $ai->chat(
            $message,
            $_SESSION['tenant_name'] ?? 'Mi Empresa',
            $_SESSION['tenant_user_name'] ?? 'Usuario',
            $history
        );

        // OpenRouter puede tardar > wait_timeout de MySQL; reconectar antes de guardar.
        $this->refreshDbConnection();
        try {
            (new AiService($this->db))->saveMessage($conversationId, 'assistant', $reply);
        } catch (\Throwable $e) {
            error_log('AI save assistant message: ' . $e->getMessage());
        }

        $this->json([
            'reply' => $reply,
            'conversation_id' => $conversationId,
        ]);
    }

    public function history(): void
    {
        $ai = new AiService($this->db);
        $action = (string)$this->request->get('action', 'list');
        $userId = $this->currentUserId();

        if ($action === 'load') {
            $conversationId = (int)$this->request->get('id', 0);
            if ($conversationId <= 0 || !$ai->conversationOwnedBy($conversationId, $userId)) {
                $this->json(['success' => false, 'messages' => []]);
                return;
            }
            $this->json([
                'success' => true,
                'conversation_id' => $conversationId,
                'messages' => $ai->messages($conversationId),
            ]);
            return;
        }

        $this->json([
            'success' => true,
            'conversations' => $ai->conversations($userId),
        ]);
    }

    private function deleteConversation(): void
    {
        $ai = new AiService($this->db);
        $userId = $this->currentUserId();
        $conversationId = (int)$this->request->post('conversation_id', 0);
        if ($conversationId > 0 && $ai->conversationOwnedBy($conversationId, $userId)) {
            $ai->deleteConversation($conversationId);
            $this->json(['success' => true]);
            return;
        }
        $this->json(['success' => false, 'message' => 'Conversación no encontrada']);
    }

    private function currentUserId(): ?int
    {
        $id = (int)($_SESSION['tenant_user_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
