{ pkgs, ... }:

{
  languages.php = {
    enable = true;
    version = "8.5";
  };

  packages = [
    pkgs.bash
    pkgs.coreutils
    pkgs.jq
    pkgs.gnugrep
    pkgs.gawk
    pkgs.diffutils
    pkgs.git
    pkgs.perl
  ];
}
