<?php
if (!defined('ABSPATH')) exit;

class IP_Utils {

    public static function is_valid_ip_or_cidr($range) {
        $range = trim($range);
        if (empty($range)) return false;

        if (filter_var($range, FILTER_VALIDATE_IP)) return true;

        if (strpos($range, '/') !== false) {
            list($ip, $mask) = explode('/', $range, 2);
            $mask = intval($mask);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) return true;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) return true;
            if (strpos($ip, ':') !== false && preg_match('/^[0-9a-fA-F:]+$/', $ip) && $mask >= 0 && $mask <= 128) return true;
            if (strpos($ip, '.') !== false && preg_match('/^[0-9.]+$/', $ip) && $mask >= 0 && $mask <= 32) return true;
        }

        return false;
    }

    public static function parse_ip_list_from_url($url, & $meta = null) {
        $meta = ['url' => $url, 'status' => null, 'count' => 0, 'error' => null];

        if (empty($url)) {
            $meta['status'] = 'invalid_url';
            $meta['error'] = 'URLが空です';
            return new WP_Error('invalid_url', 'URLが空です', $meta);
        }

        $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
        if (is_wp_error($response)) {
            $meta['status'] = 'wp_error';
            $meta['error'] = $response->get_error_message();
            ggc_debug_log('IP_Utils fetch error: ' . $meta['error'] . ' url=' . $url);
            return new WP_Error('http_fetch_failed', $response->get_error_message(), $meta);
        }

        $status = wp_remote_retrieve_response_code($response);
        $meta['status'] = $status;
        if ($status !== 200) {
            $meta['error'] = 'HTTP ' . intval($status);
            ggc_debug_log('IP_Utils http status ' . $status . ' for ' . $url);
            return new WP_Error('http_error', 'HTTP ' . intval($status), $meta);
        }

        $body = wp_remote_retrieve_body($response);

        // Delegate extraction/validation to helper so tests can exercise parsing without HTTP.
        return self::extract_ip_list_from_text($body, $meta);
    }

    /**
     * Extract and validate IP/CIDR entries from arbitrary text or JSON body.
     * Returns array of strings or WP_Error when no valid content found but some raw results exist.
     */
    public static function extract_ip_list_from_text($body, & $meta = null) {
        $meta = $meta ?? ['url' => null, 'status' => null, 'count' => 0, 'error' => null];
        $results = [];

        // Try JSON prefixes style (Google/OpenAI/AWS style)
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (isset($data['prefixes']) && is_array($data['prefixes'])) {
                foreach ($data['prefixes'] as $p) {
                    if (isset($p['ipv4Prefix'])) $results[] = sanitize_text_field($p['ipv4Prefix']);
                    if (isset($p['ipv6Prefix'])) $results[] = sanitize_text_field($p['ipv6Prefix']);
                    if (isset($p['ip_prefix'])) $results[] = sanitize_text_field($p['ip_prefix']);
                    if (isset($p['ipv6_prefix'])) $results[] = sanitize_text_field($p['ipv6_prefix']);
                    if (isset($p['ipv4_prefix'])) $results[] = sanitize_text_field($p['ipv4_prefix']);
                }
            }
            if (empty($results)) {
                $flat = [];
                array_walk_recursive($data, function($v) use (&$flat){ if (is_string($v)) $flat[] = $v; });
                $body = implode("\n", $flat);
            }
        }

        // Generic regex extraction
        if (empty($results)) {
            if (preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?\b/', $body, $m)) {
                foreach ($m[0] as $ip) $results[] = sanitize_text_field($ip);
            }
            if (preg_match_all('/\b[0-9a-fA-F:]{3,}(?:\/\d{1,3})?\b/', $body, $m2)) {
                foreach ($m2[0] as $ip) $results[] = sanitize_text_field($ip);
            }
        }

        $results = array_values(array_unique(array_filter($results)));

        // Validate
        $validated = array_values(array_filter($results, function($r){ return IP_Utils::is_valid_ip_or_cidr($r); }));

        if (empty($validated) && !empty($results)) {
            $with_mask = array_values(array_filter($results, function($r){ return strpos($r, '/') !== false && IP_Utils::is_valid_ip_or_cidr($r); }));
            if (!empty($with_mask)) {
                $meta['count'] = count($with_mask);
                ggc_debug_log('IP_Utils parsed ' . $meta['count'] . ' IPs (with mask)');
                return $with_mask;
            }
            // return any sanitized results as fallback
            $meta['count'] = count($results);
            ggc_debug_log('IP_Utils parsed ' . $meta['count'] . ' fallback IPs');
            return $results;
        }

        $meta['count'] = count($validated);
        ggc_debug_log('IP_Utils parsed ' . $meta['count'] . ' valid IPs');
        return $validated;
    }

}
