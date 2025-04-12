# Advanced Forbidden Content Checker v2.0

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-8892BF?style=flat-square)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](https://opensource.org/licenses/MIT)

A standalone PHP application to check WordPress websites for specific keywords (like "casino") within their search results, featuring a modern UI, concurrent checks, and CSV export.

---

## English Description

### Overview

The **Advanced Forbidden Content Checker** is a single-file PHP web application designed to help users scan a list of WordPress websites. It checks if the sites' internal search results contain posts mentioning the keyword "casino" or any other user-defined keywords. This tool is particularly useful for monitoring website content for specific terms quickly and efficiently.

This version (v2.0) is a significant upgrade from the original v1.0, rebuilt from the ground up with modern technologies and enhanced features.

### Key Features (v2.0)

* **Single File Application:** Combines backend (PHP) and frontend (HTML/CSS/JS) into one manageable file.
* **Modern User Interface:** Uses the [UIkit 3](https://getuikit.com/) framework for a clean, responsive, and user-friendly interface.
* **Multiple Keyword Support:** Checks for the default keyword "casino" and allows users to input multiple additional keywords (comma-separated).
* **Concurrent Checks:** Processes the list of domains/URLs concurrently (up to a configurable limit) using asynchronous JavaScript requests, significantly speeding up checks for large lists compared to v1.0's sequential approach.
* **Configurable Result Limits:** Limits the number of results shown per keyword ("casino" and each additional keyword) via constants in the PHP code.
* **Keyword Highlighting:** Found keywords are highlighted within the result titles in the output table for easy identification.
* **CSV Export:** Allows users to export the results table to a CSV file for offline analysis or record-keeping.
* **Enhanced Error Handling:** Provides clearer feedback on fetch errors (cURL errors, HTTP status codes).
* **Improved URL Parsing:** More robust handling of different URL formats and relative link resolution.
* **PHP 8.1+ Compatibility:** Utilizes modern PHP features and requires PHP version 8.1 or higher.
* **Fully English Interface:** All UI elements are presented in English for broader accessibility.

### How it Works

1.  **Input:** The user provides a list of domain names or full URLs (one per line) and optionally adds comma-separated keywords.
2.  **Backend Processing (PHP):**
    * For each domain/URL, the script constructs search URLs targeting the WordPress site's search functionality (e.g., `https://example.com/?s=keyword`).
    * It performs checks for "casino" and each specified additional keyword.
    * It uses cURL to fetch the HTML content of the search results pages.
    * It parses the HTML using `DOMDocument` and `DOMXPath` to find links (`<a>` tags) whose text contains the target keywords (case-insensitive).
    * It extracts the title and link (resolving relative URLs) for matching posts, respecting the configured result limits.
    * It returns the findings (or error details) as a JSON response.
3.  **Frontend Processing (JavaScript):**
    * The JavaScript sends asynchronous POST requests to the same PHP script for each domain/URL, managing concurrency up to the defined limit (`MAX_CONCURRENT_REQUESTS`).
    * It updates a progress bar as checks complete.
    * It receives the JSON response for each domain and dynamically populates a results table using UIkit components.
    * Status indicators (Found/Not Found/Error) and highlighted keywords provide quick visual feedback.
    * An "Export to CSV" button becomes available upon completion.

### Requirements

* PHP >= 8.1 (with cURL and DOM extensions enabled, which are standard)
* A web server (like Apache or Nginx) capable of running PHP scripts.
* A modern web browser that supports Fetch API and other ES6+ JavaScript features.

### How to Use

1.  Download the single PHP file (e.g., `forbidden_checker.php`) from this repository.
2.  Upload the file to your web server.
3.  Access the file through your web browser (e.g., `http://yourdomain.com/forbidden_checker.php`).
4.  Enter the list of domains or URLs you want to check in the text area, one per line.
5.  Optionally, enter additional keywords (separated by commas) in the corresponding input field.
6.  Click the "Start Checking" button.
7.  Monitor the progress bar and view the results in the table that appears.
8.  Once completed, click the "Export to CSV" button if you wish to download the results.

---

## Türkçe Açıklama

### Genel Bakış

**Advanced Forbidden Content Checker**, kullanıcıların bir WordPress web sitesi listesini taramasına yardımcı olmak için tasarlanmış, tek dosyadan oluşan bir PHP web uygulamasıdır. Sitelerin iç arama sonuçlarında "casino" anahtar kelimesini veya kullanıcı tanımlı diğer anahtar kelimeleri içeren gönderiler olup olmadığını kontrol eder. Bu araç, özellikle web sitesi içeriklerini belirli terimler açısından hızlı ve verimli bir şekilde izlemek için kullanışlıdır.

Bu sürüm (v2.0), modern teknolojiler ve geliştirilmiş özelliklerle sıfırdan yeniden oluşturulmuş, orijinal v1.0'dan önemli ölçüde yükseltilmiş bir versiyondur.

### Önemli Özellikler (v2.0)

* **Tek Dosya Uygulaması:** Arka uç (PHP) ve ön uç (HTML/CSS/JS) mantığını yönetimi kolay tek bir dosyada birleştirir.
* **Modern Kullanıcı Arayüzü:** Temiz, duyarlı ve kullanıcı dostu bir arayüz için [UIkit 3](https://getuikit.com/) framework'ünü kullanır.
* **Çoklu Anahtar Kelime Desteği:** Varsayılan "casino" anahtar kelimesini kontrol eder ve kullanıcıların virgülle ayrılmış birden fazla ek anahtar kelime girmesine olanak tanır.
* **Eş Zamanlı Kontroller:** Alan adı/URL listesini eş zamanlı olarak (yapılandırılabilir bir sınıra kadar) asenkron JavaScript istekleri kullanarak işler, bu da v1.0'ın sıralı yaklaşımına kıyasla büyük listeler için kontrolleri önemli ölçüde hızlandırır.
* **Yapılandırılabilir Sonuç Limitleri:** PHP kodundaki sabitler aracılığıyla anahtar kelime başına ("casino" ve her ek anahtar kelime için) gösterilen sonuç sayısını sınırlar.
* **Anahtar Kelime Vurgulama:** Bulunan anahtar kelimeler, kolay tanımlama için çıktı tablosundaki sonuç başlıklarında vurgulanır.
* **CSV Dışa Aktarma:** Kullanıcıların çevrimdışı analiz veya kayıt tutma için sonuç tablosunu bir CSV dosyasına aktarmasına olanak tanır.
* **Geliştirilmiş Hata Yönetimi:** Getirme hataları (cURL hataları, HTTP durum kodları) hakkında daha net geri bildirim sağlar.
* **İyileştirilmiş URL Ayrıştırma:** Farklı URL formatlarının ve göreceli bağlantı çözümlemesinin daha sağlam bir şekilde ele alınmasını sağlar.
* **PHP 8.1+ Uyumluluğu:** Modern PHP özelliklerini kullanır ve PHP sürüm 8.1 veya üstünü gerektirir.
* **Tamamen İngilizce Arayüz:** Daha geniş erişilebilirlik için tüm kullanıcı arayüzü öğeleri İngilizce olarak sunulur.

### Nasıl Çalışır

1.  **Girdi:** Kullanıcı, alan adlarının veya tam URL'lerin bir listesini (her satıra bir tane) girer ve isteğe bağlı olarak virgülle ayrılmış ek anahtar kelimeler ekler.
2.  **Arka Uç İşlemleri (PHP):**
    * Her alan adı/URL için betik, WordPress sitesinin arama işlevini hedefleyen arama URL'leri oluşturur (örneğin, `https://example.com/?s=keyword`).
    * "Casino" ve belirtilen her ek anahtar kelime için kontroller gerçekleştirir.
    * Arama sonuçları sayfalarının HTML içeriğini getirmek için cURL kullanır.
    * Metinleri hedef anahtar kelimeleri (büyük/küçük harfe duyarsız) içeren bağlantıları (`<a>` etiketleri) bulmak için `DOMDocument` ve `DOMXPath` kullanarak HTML'yi ayrıştırır.
    * Yapılandırılmış sonuç limitlerine uyarak eşleşen gönderiler için başlığı ve bağlantıyı (göreceli URL'leri çözerek) çıkarır.
    * Bulguları (veya hata ayrıntılarını) bir JSON yanıtı olarak döndürür.
3.  **Ön Uç İşlemleri (JavaScript):**
    * JavaScript, her alan adı/URL için aynı PHP betiğine asenkron POST istekleri gönderir ve eş zamanlılığı tanımlanan sınıra (`MAX_CONCURRENT_REQUESTS`) kadar yönetir.
    * Kontroller tamamlandıkça bir ilerleme çubuğunu günceller.
    * Her alan adı için JSON yanıtını alır ve UIkit bileşenlerini kullanarak dinamik olarak bir sonuç tablosunu doldurur.
    * Durum göstergeleri (Bulundu/Bulunamadı/Hata) ve vurgulanan anahtar kelimeler hızlı görsel geri bildirim sağlar.
    * Tamamlandığında bir "Export to CSV" düğmesi kullanılabilir hale gelir.

### Gereksinimler

* PHP >= 8.1 (cURL ve DOM eklentileri etkinleştirilmiş olarak, ki bunlar standarttır)
* PHP betiklerini çalıştırabilen bir web sunucusu (Apache veya Nginx gibi).
* Fetch API ve diğer ES6+ JavaScript özelliklerini destekleyen modern bir web tarayıcısı.

### Nasıl Kullanılır

1.  Bu depodan tek PHP dosyasını (örneğin, `forbidden_checker.php`) indirin.
2.  Dosyayı web sunucunuza yükleyin.
3.  Dosyaya web tarayıcınız üzerinden erişin (örneğin, `http://alanadiniz.com/forbidden_checker.php`).
4.  Kontrol etmek istediğiniz alan adlarının veya URL'lerin listesini metin alanına her satıra bir tane olacak şekilde girin.
5.  İsteğe bağlı olarak, ilgili giriş alanına ek anahtar kelimeleri (virgülle ayırarak) girin.
6.  "Start Checking" düğmesine tıklayın.
7.  İlerleme çubuğunu izleyin ve görünen tablodaki sonuçları görüntüleyin.
8.  Tamamlandığında, sonuçları indirmek isterseniz "Export to CSV" düğmesine tıklayın.


---

## Original Author (v1.0)

* **Ercan ATAY** - [https://www.ercanatay.com](https://www.ercanatay.com)

*(v2.0 developed based on the original concept)*

---

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details (assuming you add an MIT license file).
