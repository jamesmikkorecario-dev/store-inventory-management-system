<span class="{{ $unreadAlertsCount > 0 ? 'ms-1.5 text-rose-600 dark:text-rose-400' : 'hidden' }}" style="{{ $unreadAlertsCount > 0 ? '' : 'display: none;' }}">
    {{ $unreadAlertsCount > 99 ? '99+' : $unreadAlertsCount }}
</span>
