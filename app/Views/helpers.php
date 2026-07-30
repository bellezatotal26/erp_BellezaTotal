<?php

/**
 * Funciones helper compartidas entre todas las vistas
 * Incluidas automáticamente por View::render()
 */

if (!function_exists('formatDate')) {
    function formatDate(?string $date): string {
        if (empty($date) || $date === '0000-00-00') {
            return '-';
        }
        return date('d/m/Y', strtotime($date));
    }
}

if (!function_exists('daysRemaining')) {
    function daysRemaining(?string $endDate): int {
        if (empty($endDate) || $endDate === '0000-00-00') {
            return 0;
        }
        $end = new DateTime($endDate);
        $today = new DateTime(date('Y-m-d'));
        $diff = $today->diff($end);
        return (int) $diff->format('%r%a');
    }
}

if (!function_exists('statusLabel')) {
    function statusLabel(string $status): string {
        return match ($status) {
            'active' => 'Activo',
            'suspended' => 'Suspendido',
            'cancelled' => 'Cancelado',
            'inactive' => 'Inactivo',
            'blocked' => 'Bloqueado',
            'expired' => 'Expirado',
            'pending' => 'Pendiente',
            default => ucfirst($status),
        };
    }
}

if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass(string $status): string {
        return match ($status) {
            'active' => 'badge-success',
            'suspended', 'blocked' => 'badge-warning',
            'cancelled', 'inactive', 'expired' => 'badge-danger',
            'pending' => 'badge-info',
            default => 'badge-info',
        };
    }
}

if (!function_exists('ticketStatusLabel')) {
    function ticketStatusLabel(string $status): string {
        return match ($status) {
            'open' => 'Abierto',
            'in_progress' => 'En Progreso',
            'resolved' => 'Resuelto',
            'closed' => 'Cerrado',
            default => ucfirst($status),
        };
    }
}

if (!function_exists('ticketStatusBadgeClass')) {
    function ticketStatusBadgeClass(string $status): string {
        return match ($status) {
            'open' => 'badge-danger',
            'in_progress' => 'badge-warning',
            'resolved' => 'badge-success',
            'closed' => 'badge-info',
            default => 'badge-info',
        };
    }
}

if (!function_exists('ticketPriorityLabel')) {
    function ticketPriorityLabel(string $priority): string {
        return match ($priority) {
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            'urgent' => 'Urgente',
            default => ucfirst($priority),
        };
    }
}

if (!function_exists('ticketPriorityBadgeClass')) {
    function ticketPriorityBadgeClass(string $priority): string {
        return match ($priority) {
            'low' => 'badge-info',
            'medium' => 'badge-success',
            'high' => 'badge-warning',
            'urgent' => 'badge-danger',
            default => 'badge-info',
        };
    }
}

if (!function_exists('ticketCategoryLabel')) {
    function ticketCategoryLabel(string $category): string {
        return match ($category) {
            'support' => 'Soporte',
            'consultation' => 'Consulta',
            'bug' => 'Error',
            'feature_request' => 'Solicitud',
            'other' => 'Otro',
            default => ucfirst($category),
        };
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo(string $datetime): string {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) return 'Ahora';
        if ($diff < 3600) return floor($diff / 60) . ' min';
        if ($diff < 86400) return floor($diff / 3600) . ' h';
        if ($diff < 604800) return floor($diff / 86400) . ' d';
        return date('d/m/Y', $time);
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime(string $datetime): string {
        return date('d/m/Y H:i', strtotime($datetime));
    }
}

if (!function_exists('flashMessage')) {
    function flashMessage(): string {
        $html = '';
        if (isset($_SESSION['success'])) {
            $html .= '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success']) . '</div>';
            unset($_SESSION['success']);
        }
        if (isset($_SESSION['error'])) {
            $html .= '<div class="alert alert-error">' . htmlspecialchars($_SESSION['error']) . '</div>';
            unset($_SESSION['error']);
        }
        return $html;
    }
}
