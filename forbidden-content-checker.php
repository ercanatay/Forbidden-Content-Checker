<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backlink Kontrol</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #dddddd;
            text-align: left;
            padding: 8px;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .results {
            display: none;
        }
    </style>
</head>
<body>
    <form id="checkForm">
        <label for="backlinks">Backlink URL'leri:</label>
        <textarea id="backlinks" name="backlinks" rows="5" cols="50" required></textarea>
        <br>
        <label for="extraKeywords">Ek Anahtar Kelime (1 adet):</label>
        <input type="text" id="extraKeywords" name="extraKeywords">
        <br>
        <input type="submit" value="Kontrol Et">
    </form>
	<h4>Çalışma prensibi:</h4>
	<ul>
	<li>10'ar 10'ar kontrol eder.</li>
		<li>Ek anahtar kelime 1 tane girebilirsiniz.</li>
		<li>Proje ana yapısı Wordpress arama sorgusu üzerinden yapılır.</li>
		<li>Eğer site araması klasik sorguya kapalı ise "Unable to fetch data" hatası verir.</li>
		<li>Casino maksimum 3 tane içerik listeler.</li>
		<li>Ek anahtar kelime maksimum 2 tane içerik listeler.</li>
	</ul>


    <div class="results">
        <p>İşlem durumu: <span class="percentage">0%</span> (<span class="count">0</span> kontrol edildi)</p>
        <table>
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Casino içeriği var mı</th>
                    <th>Casino Blog Title</th>
                    <th>Casino Blog Links</th>
                    <th>Ek Anahtar Kelime Kontrol</th>
                    <th>Ek Anahtar Kelime Blog Title</th>
                    <th>Ek Anahtar Kelime Blog Links</th>
                </tr>
            </thead>
            <tbody class="resultBody">
            </tbody>
        </table>
    </div>

    <script>
        document.getElementById("checkForm").addEventListener("submit", async function (event) {
            event.preventDefault();
            const backlinks = document.getElementById("backlinks").value.trim().split("\n").filter(line => line.trim() !== "");
            const extraKeywords = document.getElementById("extraKeywords").value.trim().split(",");

            const results = document.querySelector(".results");
            const percentage = document.querySelector(".percentage");
            const count = document.querySelector(".count");
            const resultBody = document.querySelector(".resultBody");

            results.style.display = "block";
            resultBody.innerHTML = "";

            for (let i = 0; i < backlinks.length; i++) {
                const domain = backlinks[i].trim();

                const response = await fetch("check.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({domain, extraKeywords})
                });

                const data = await response.json();
                const row = document.createElement("tr");

                for (const value of Object.values(data)) {
                    const cell = document.createElement("td");
                    cell.innerHTML = value;
                    row.appendChild(cell);
                }

                resultBody.appendChild(row);

                percentage.textContent = Math.round(((i + 1) / backlinks.length) * 100) + "%";
                count.textContent = i + 1;
            }
        });
    </script>
</body>
</html>
