<?php
/**
 * Production Configuration for User Activity Logging
 * Environment-specific settings and security configurations
 * 
 * @author GitHub Copilot
 * @version 2.0 - Production Ready
 * @since November 3, 2025
 */

// Determine environment
$environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
$is_production = ($environment === 'production');
$is_development = ($environment === 'development' || $environment === 'local');

// Production-ready configuration
$activity_logger_config = [
    // Core Settings
    'environment' => $environment,
    'debug_mode' => !$is_production,
    
    // Security Settings
    'enable_rate_limiting' => true,
    'rate_limit_threshold' => $is_production ? 50 : 100, // Stricter in production
    'sanitize_inputs' => true,
    'validate_user_ids' => true,
    'max_description_length' => 1000,
    'max_device_info_length' => 500,
    
    // Performance Settings
    'enable_performance_monitoring' => true,
    'enable_detailed_logging' => $is_development, // Only in dev
    'connection_retry_attempts' => 3,
    'connection_retry_delay' => 1,
    'batch_size' => $is_production ? 200 : 50,
    
    // Monitoring & Alerting
    'alert_on_failures' => $is_production,
    'slow_query_threshold' => 1.0, // seconds
    'enable_metrics_collection' => true,
    
    // Data Retention
    'log_retention_days' => $is_production ? 365 : 90,
    'enable_auto_cleanup' => $is_production,
    'cleanup_schedule' => 'daily', // daily, weekly, monthly
    
    // Storage Settings
    'compress_old_logs' => $is_production,
    'archive_threshold_days' => 30,
    'max_log_file_size_mb' => 100,
    
    // Security Headers and Validation
    'allowed_ip_ranges' => [
        // Add your allowed IP ranges for production
        '192.168.0.0/16',    // Private network
        '10.0.0.0/8',        // Private network
        '172.16.0.0/12',     // Private network
        '127.0.0.0/8',       // Localhost
    ],
    
    // Integration Settings
    'webhook_alerts' => [
        'enabled' => false,
        'endpoints' => [
            // 'https://hooks.slack.com/services/...' // Slack webhook
            // 'https://api.pagerduty.com/...'        // PagerDuty
        ]
    ],
    
    // Email Alerts
    'email_alerts' => [
        'enabled' => false,
        'recipients' => [
            // 'admin@hospital.com',
            // 'security@hospital.com'
        ],
        'smtp_config' => [
            'host' => $_ENV['SMTP_HOST'] ?? 'localhost',
            'port' => $_ENV['SMTP_PORT'] ?? 587,
            'username' => $_ENV['SMTP_USERNAME'] ?? '',
            'password' => $_ENV['SMTP_PASSWORD'] ?? '',
            'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls'
        ]
    ],
    
    // Database Settings
    'database' => [
        'connection_timeout' => 10,
        'query_timeout' => 30,
        'max_retries' => 3,
        'use_transactions' => true,
        'connection_pool_size' => $is_production ? 10 : 5,
    ],
    
    // Cache Settings (for rate limiting, etc.)
    'cache' => [
        'driver' => 'file', // file, redis, memcached
        'ttl' => 300, // 5 minutes
        'path' => dirname(__DIR__) . '/cache/activity_logger'
    ],
    
    // Log File Settings
    'log_files' => [
        'error_log' => dirname(__DIR__) . '/logs/activity_logger_errors.log',
        'access_log' => dirname(__DIR__) . '/logs/activity_logger_access.log',
        'performance_log' => dirname(__DIR__) . '/logs/activity_logger_performance.log',
        'security_log' => dirname(__DIR__) . '/logs/activity_logger_security.log'
    ],
    
    // Compliance Settings
    'compliance' => [
        'encrypt_sensitive_data' => $is_production,
        'anonymize_ip_addresses' => false, // Set true for GDPR compliance
        'data_retention_policy' => 'automatic',
        'audit_trail_required' => true,
        'require_reason_for_access' => $is_production
    ],
    
    // Feature Flags
    'features' => [
        'bulk_logging' => true,
        'real_time_monitoring' => $is_production,
        'automated_maintenance' => $is_production,
        'geographic_tracking' => false, // Enable if needed
        'session_correlation' => true,
        'anomaly_detection' => $is_production
    ]
];

// Environment-specific overrides
switch ($environment) {
    case 'production':
        $activity_logger_config = array_merge($activity_logger_config, [
            'alert_on_failures' => true,
            'enable_detailed_logging' => false,
            'rate_limit_threshold' => 50,
            'webhook_alerts' => [
                'enabled' => true,
                'endpoints' => [
                    // Add production webhook URLs here
                ]
            ]
        ]);
        break;
        
    case 'staging':
        $activity_logger_config = array_merge($activity_logger_config, [
            'alert_on_failures' => false,
            'enable_detailed_logging' => true,
            'rate_limit_threshold' => 75,
            'log_retention_days' => 30
        ]);
        break;
        
    case 'development':
    case 'local':
        $activity_logger_config = array_merge($activity_logger_config, [
            'alert_on_failures' => false,
            'enable_detailed_logging' => true,
            'rate_limit_threshold' => 200,
            'log_retention_days' => 7,
            'enable_auto_cleanup' => false
        ]);
        break;
}

// Validate configuration
function validateActivityLoggerConfig($config) {
    $required_keys = [
        'environment', 'enable_rate_limiting', 'sanitize_inputs',
        'validate_user_ids', 'log_retention_days'
    ];
    
    foreach ($required_keys as $key) {
        if (!isset($config[$key])) {
            throw new InvalidArgumentException("Missing required config key: $key");
        }
    }
    
    // Validate numeric values
    if ($config['log_retention_days'] < 1 || $config['log_retention_days'] > 3650) {
        throw new InvalidArgumentException("Invalid log_retention_days: must be between 1 and 3650");
    }
    
    if ($config['rate_limit_threshold'] < 1 || $config['rate_limit_threshold'] > 1000) {
        throw new InvalidArgumentException("Invalid rate_limit_threshold: must be between 1 and 1000");
    }
    
    return true;
}

// Validate the configuration
validateActivityLoggerConfig($activity_logger_config);

// Export configuration
return $activity_logger_config;
?>