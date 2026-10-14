<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Entry;

class EntryController
{
    private Entry $entryModel;

    public function __construct()
    {
        $this->entryModel = new Entry();
    }

    public function showEditor(): void
    {
        $apiUrl = e(url('api/entries'));
        $historyUrl = e(url('entry/history'));
        $today = date('Y-m-d');
        $csrf =  e(csrf_token());

        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Νέα Καταγραφή</title></head>
        <body>
            <h1>Νέα Καταγραφή</h1>
            <p><a href="' . $historyUrl . '">Ιστορικό</a></p>
            <form id="entryForm">
                <input type="date" id="entry_date" value="' . e($today) . '"><br>
                <textarea id="body" rows="10" cols="50" placeholder="Γράψε για την ημέρα σου..." required></textarea><br>
                <button type="submit">Αποθήκευση</button>
            </form>
            <p id="msg"></p>
            <script>
            document.getElementById("entryForm").addEventListener("submit", async (ev) => {
                ev.preventDefault();
                const msg = document.getElementById("msg");
                msg.textContent = "Αποθήκευση...";
                try {
                    const res = await fetch("' . $apiUrl . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" , "X-CSRF-Token" : "' . $csrf . '"},
                        body: JSON.stringify({
                            body: document.getElementById("body").value,
                            entry_date: document.getElementById("entry_date").value
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        msg.textContent = "Αποθηκεύτηκε! (id: " + data.data.id + ")";
                        document.getElementById("body").value = "";
                    } else {
                        msg.textContent = "Σφάλμα: " + (data.error || "άγνωστο");
                    }
                } catch (err) {
                    msg.textContent = "Σφάλμα δικτύου.";
                }
            });
            </script>
        </body></html>';
    }

    public function history(): void
    {
        $userId = (int) current_user_id();
        $entries = $this->entryModel->allForUser($userId, 50, 0);

        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Ιστορικό</title></head>
        <body>
            <h1>Ιστορικό Καταγραφών</h1>
            <p><a href="' . e(url('entry/editor')) . '">Νέα Καταγραφή</a></p>';

        if (count($entries) === 0) {
            echo '<p>Δεν υπάρχουν καταγραφές ακόμα.</p>';
        } else {
            echo '<ul>';
            foreach ($entries as $entry) {
                $viewUrl = e(url('entry/' . $entry['id']));
                $date = e((string) $entry['entry_date']);
                $emotion = $entry['emotion_emoji'] ? e((string) $entry['emotion_emoji']) . ' ' : '';
                $preview = e(mb_substr((string) $entry['body'], 0, 60));
                echo '<li>' . $emotion . '<a href="' . $viewUrl . '">' . $date . '</a> — ' . $preview . '...</li>';
            }
            echo '</ul>';
        }

        echo '</body></html>';
    }

    public function view(string $id): void
    {
        $userId = (int) current_user_id();
        $entry = $this->entryModel->findByIdForUser((int) $id, $userId);

        if ($entry === null) {
            http_response_code(404);
            echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><title>Δεν βρέθηκε</title></head>
            <body><h1>Η καταγραφή δεν βρέθηκε</h1>
            <p><a href="' . e(url('entry/history')) . '">Πίσω στο ιστορικό</a></p></body></html>';
            return;
        }

        $emotion = $entry['emotion_name']
            ? e((string) $entry['emotion_emoji']) . ' ' . e((string) $entry['emotion_name'])
            : '—';

        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Καταγραφή</title></head>
        <body>
            <h1>Καταγραφή ' . e((string) $entry['entry_date']) . '</h1>
            <p><strong>Συναίσθημα:</strong> ' . $emotion . '</p>
            <p><strong>Sentiment:</strong> ' . e((string) ($entry['sentiment_score'] ?? '—')) . '</p>
            <hr>
            <p>' . nl2br(e((string) $entry['body'])) . '</p>
            <hr>
            <p><a href="' . e(url('entry/history')) . '">Πίσω στο ιστορικό</a></p>
        </body></html>';
    }
}