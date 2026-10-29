{
  description = "PHP and WordPress development environment";

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs =
    { nixpkgs, ... }:
    let
      system = "x86_64-linux";
      pkgs = import nixpkgs {
        inherit system;
        # Intelephense's basic features are free to use, but not open source.
        config.allowUnfreePredicate = pkg: nixpkgs.lib.getName pkg == "intelephense";
      };
      php = pkgs.php84.buildEnv {
        extensions =
          { enabled, all }:
          enabled
          ++ (with all; [
            gd
            intl
            mysqli
            pdo_mysql
            zip
          ]);
        extraConfig = ''
          memory_limit = 512M
          display_errors = On
          error_reporting = E_ALL
        '';
      };
    in
    {
      devShells.${system}.default = pkgs.mkShell {
        packages = [
          php
          php.packages.composer
          php.packages.php-cs-fixer
          php.packages.php-codesniffer
          (pkgs.phpstan.override { inherit php; })
          (pkgs.phpunit.override { inherit php; })
          (pkgs.wp-cli.override { inherit php; })
        ]
        ++ (with pkgs; [
          intelephense
          git
          unzip
          # WordPress themes, blocks and other frontend assets.
          nodejs
          typescript
          typescript-language-server
          vscode-langservers-extracted
          emmet-language-server
          tailwindcss-language-server
          prettierd
        ]);
      };
    };
}
