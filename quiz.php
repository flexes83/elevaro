<?php
$questions = json_decode(file_get_contents("data/questions.json"), true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Quiz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h2 id="question"></h2>
    <div id="answers" class="mt-4"></div>
</div>
<script>
let questions = <?php echo json_encode($questions); ?>;
let index = 0;
function loadQuestion(){
    let q = questions[index];
    document.getElementById("question").innerText = q.question;
    let answers = "";
    q.options.forEach(opt => {
        answers += `<button class="btn btn-outline-primary m-2" onclick="check('${opt}')">${opt}</button>`;
    });
    document.getElementById("answers").innerHTML = answers;
}
function check(answer){
    let correct = questions[index].answer;
    if(answer === correct){
        alert("Nice! Weiter so 👀");
    } else {
        alert("Falsch 😅");
    }
    index++;
    if(index < questions.length){
        loadQuestion();
    } else {
        window.location = "index.php";
    }
}
loadQuestion();
</script>
</body>
</html>
