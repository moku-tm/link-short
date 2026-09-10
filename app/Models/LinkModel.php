<?php

require_once __DIR__ . '/../Core/Database.php';

class LinkModel
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function generateCode($length = 7)
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($chars) - 1;
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, $max)];
            }
        } while ($this->codeExists($code));
        return $code;
    }

    public function codeExists($code)
    {
        $result = $this->db->fetch("SELECT id FROM links WHERE code = ?", [$code]);
        return $result !== false;
    }

    public function create($url, $code, $ip)
    {
        return $this->db->insert('links', [
            'code' => $code,
            'original_url' => $url,
            'clicks' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'ip_address' => $ip,
        ]);
    }

    public function findByCode($code)
    {
        return $this->db->fetch("SELECT * FROM links WHERE code = ?", [$code]);
    }

    public function incrementClicks($code)
    {
        $this->db->query("UPDATE links SET clicks = clicks + 1 WHERE code = ?", [$code]);
    }

    public function deleteExpired($days = 365)
    {
        $this->db->query("DELETE FROM links WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
    }

    public function getTotalLinks()
    {
        return $this->db->count('links');
    }

    public function getTotalClicks()
    {
        $result = $this->db->fetch("SELECT COALESCE(SUM(clicks), 0) as total FROM links");
        return $result['total'] ?? 0;
    }

    public function getRecentLinks($limit = 5)
    {
        return $this->db->fetchAll("SELECT code, original_url, clicks, created_at FROM links ORDER BY created_at DESC LIMIT ?", [$limit]);
    }

    public function isExpired($code)
    {
        $link = $this->findByCode($code);
        if (!$link) return true;
        $created = strtotime($link['created_at']);
        return (time() - $created) > (365 * 86400);
    }

    public function deleteByCode($code)
    {
        $this->db->delete('links', 'code = ?', [$code]);
    }
}
