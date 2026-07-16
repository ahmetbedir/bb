# Demo recording for the README.
#   vhs assets/demo.tape   ->   assets/demo.gif
#
# Uses an isolated, throwaway config (no real credentials needed).

Output assets/demo.gif

Require bb

Set Shell bash
Set FontSize 15
Set Width 1080
Set Height 760
Set Padding 18
Set Theme "Dracula"
Set TypingSpeed 60ms

Hide
Type "export PATH=$HOME/.local/bin:$PATH; export BB_HOME=$(mktemp -d)" Enter
Type "clear" Enter
Show

Type "bb --version" Sleep 400ms Enter
Sleep 1.5s

Type "bb alias co pr:checkout" Sleep 400ms Enter
Sleep 1.5s

Type "bb config default_destination staging" Sleep 400ms Enter
Sleep 1.2s

Type "bb config" Sleep 400ms Enter
Sleep 2.5s

Type "bb pr:create --help" Sleep 400ms Enter
Sleep 3s
